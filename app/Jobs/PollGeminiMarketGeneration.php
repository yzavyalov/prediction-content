<?php

namespace App\Jobs;

use App\Models\MarketGenerationRun;
use App\Services\AI\GeminiService;
use App\Services\Generation\GenerationRunService;
use App\Services\Markets\MarketGenerationService;
use App\Services\Markets\MarketPersistenceService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PollGeminiMarketGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 60;

    private const POLL_INTERVAL = 60;

    private const MAX_WAIT_SECONDS = 1800;

    public function __construct(
        public readonly int $marketGenerationRunId,
    ) {
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'gemini-market-poll-' . $this->marketGenerationRunId
            ))
                ->releaseAfter(30)
                ->expireAfter(180),
        ];
    }

    public function handle(
        GeminiService $gemini,
        MarketGenerationService $generation,
        MarketPersistenceService $persistence,
        GenerationRunService $generationRunService,
    ): void {
        $run = MarketGenerationRun::findOrFail(
            $this->marketGenerationRunId
        );

        if ($run->status !== 'running') {
            return;
        }

        if ($this->isTimedOut($run)) {
            $this->failRun(
                $run,
                'Gemini background generation exceeded 30 minutes.',
                $generationRunService
            );

            return;
        }

        if ($run->gemini_stage === 'search_starting') {
            Log::warning(
                'Gemini search submission requires reconciliation',
                [
                    'market_generation_run_id' => $run->id,
                ]
            );

            return;
        }

        if (empty($run->gemini_interaction_id)) {
            $this->failRun(
                $run,
                'Running generation has no Gemini interaction ID.',
                $generationRunService
            );

            return;
        }

        try {
            $response = $gemini->getBackground(
                $run->gemini_interaction_id
            );
        } catch (Throwable $e) {
            Log::warning('Gemini background polling failed', [
                'market_generation_run_id' => $run->id,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $run->update([
            'gemini_last_checked_at' => now(),
        ]);

        $status = $response['status'] ?? null;

        if (in_array($status, ['queued', 'in_progress'], true)) {
            $this->scheduleNextPoll($run);

            return;
        }

        if ($status !== 'completed') {
            $message = 'Gemini interaction ended with status: '
                . (string) ($status ?? 'unknown');

            if (isset($response['error'])) {
                $message .= '. '
                    . json_encode(
                        $response['error'],
                        JSON_UNESCAPED_UNICODE
                    );
            }

            $this->failRun(
                $run,
                $message,
                $generationRunService
            );

            return;
        }

        $generatedAt = CarbonImmutable::instance(
            $run->gemini_started_at ?? $run->started_at
        );

        if ($run->gemini_stage === 'url_context') {
            $first = $generation->handleFirstBackgroundResponse(
                event: $run->event,
                response: $response,
                generatedAt: $generatedAt,
            );

            if ($first['needs_search']) {
                $this->startSearch(
                    $run,
                    $generation,
                    $first,
                    $response
                );

                return;
            }

            $result = $first['result'];
        } elseif ($run->gemini_stage === 'google_search') {
            $result = $generation->handleSearchBackgroundResponse(
                event: $run->event,
                response: $response,
                generatedAt: $generatedAt,
                firstUsage: $run->gemini_first_usage ?? [],
            );
        } else {
            $this->failRun(
                $run,
                'Unexpected Gemini generation stage: '
                . (string) $run->gemini_stage,
                $generationRunService
            );

            return;
        }

        $this->saveResult(
            $run,
            $result,
            $persistence,
            $generationRunService
        );
    }

    private function startSearch(
        MarketGenerationRun $run,
        MarketGenerationService $generation,
        array $first,
        array $response,
    ): void {
        /*
         * Mark the stage BEFORE sending a second paid request.
         *
         * If the request succeeds but the worker loses its
         * connection before receiving the interaction ID,
         * the system must not submit another request blindly.
         */
        $claimed = MarketGenerationRun::query()
            ->whereKey($run->id)
            ->where('status', 'running')
            ->where('gemini_stage', 'url_context')
            ->update([
                'gemini_stage' => 'search_starting',
                'gemini_first_response' => $response,
                'gemini_first_usage' => $first['first_usage'],
            ]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $interaction = $generation->startSearchBackground(
                $first['search_prompt']
            );
        } catch (Throwable $e) {
            Log::error('Gemini search submission uncertain', [
                'market_generation_run_id' => $run->id,
                'message' => $e->getMessage(),
            ]);

            /*
             * Keep search_starting.
             *
             * An automatic retry could create a duplicate
             * paid Gemini interaction.
             */
            return;
        }

        $run->update([
            'gemini_interaction_id' => $interaction['id'],
            'gemini_stage' => 'google_search',
            'gemini_last_checked_at' => null,
        ]);

        $this->scheduleNextPoll($run);
    }

    private function saveResult(
        MarketGenerationRun $run,
        array $result,
        MarketPersistenceService $persistence,
        GenerationRunService $generationRunService,
    ): void {
        $usage = $result['usage'] ?? [];

        DB::transaction(function () use (
            $run,
            $result,
            $usage,
            $persistence
        ) {
            $locked = MarketGenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($run->id);

            if ($locked->status !== 'running') {
                return;
            }

            $persistenceResult = $persistence->persist(
                $locked->event,
                $result['markets'],
                $locked->generation_run_id
            );

            $locked->update([
                'status' => 'completed',
                'gemini_stage' => 'completed',
                'markets_count' => $persistenceResult['created_count'],
                'input_tokens' => (int) (
                    $usage['total_input_tokens'] ?? 0
                ),
                'output_tokens' => (int) (
                    $usage['total_output_tokens'] ?? 0
                ),
                'finished_at' => now(),
                'error_message' => null,
            ]);
        });

        $this->syncProgress(
            $run,
            $generationRunService
        );
    }

    private function scheduleNextPoll(
        MarketGenerationRun $run
    ): void {
        self::dispatch($run->id)
            ->delay(
                now()->addSeconds(self::POLL_INTERVAL)
            );
    }

    private function isTimedOut(
        MarketGenerationRun $run
    ): bool {
        $startedAt = $run->gemini_started_at
            ?? $run->started_at;

        if ($startedAt === null) {
            return true;
        }

        return $startedAt->copy()
            ->addSeconds(self::MAX_WAIT_SECONDS)
            ->isPast();
    }

    private function failRun(
        MarketGenerationRun $run,
        string $message,
        GenerationRunService $generationRunService,
    ): void {
        $run->update([
            'status' => 'failed',
            'gemini_stage' => 'failed',
            'finished_at' => now(),
            'error_message' => $message,
        ]);

        Log::error('Gemini market generation failed', [
            'market_generation_run_id' => $run->id,
            'message' => $message,
        ]);

        $this->syncProgress(
            $run,
            $generationRunService
        );
    }

    private function syncProgress(
        MarketGenerationRun $run,
        GenerationRunService $generationRunService,
    ): void {
        foreach ($run->generationRuns as $generationRun) {
            $generationRunService->syncProgress(
                $generationRun
            );
        }
    }
}
