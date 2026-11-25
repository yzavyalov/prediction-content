<?php

namespace App\Services\Markets;

use App\Jobs\PollGeminiMarketGeneration;
use App\Models\MarketGenerationRun;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class MarketProcessingService
{
    public function __construct(
        private readonly MarketGenerationService $generation,
        private readonly MarketPersistenceService $persistence,
    ) {
    }

    public function process(
        MarketGenerationRun $run
    ): MarketGenerationRun {
        $claimed = MarketGenerationRun::query()
            ->whereKey($run->id)
            ->where('status', 'pending')
            ->whereNull('gemini_interaction_id')
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
                'error_message' => null,
                'gemini_stage' => 'starting',
                'gemini_started_at' => now(),
            ]);

        if ($claimed !== 1) {
            throw new InvalidArgumentException(
                'Market Generation Run is not eligible for processing.'
            );
        }

        $run->refresh();

        try {
            $event = $run->event;

            $started = $this->generation->startBackground(
                event: $event,
                generatedAt: $run->gemini_started_at->toImmutable(),
            );

            if ($started['empty']) {
                $persistenceResult = $this->persistence->persist(
                    $event,
                    [],
                    $run->generation_run_id
                );

                $run->update([
                    'status' => 'completed',
                    'markets_count' => $persistenceResult['created_count'],
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'finished_at' => now(),
                    'error_message' => null,
                    'gemini_stage' => 'completed',
                ]);

                return $run->refresh();
            }

            $run->update([
                'gemini_interaction_id' => $started['interaction_id'],
                'gemini_stage' => 'url_context',
            ]);

            PollGeminiMarketGeneration::dispatch($run->id)
                ->delay(now()->addSeconds(60));

            return $run->refresh();
        } catch (Throwable $e) {
            Log::error('Market generation background start failed', [
                'market_generation_run_id' => $run->id,
                'event_id' => $run->event_id,
                'message' => $e->getMessage(),
            ]);

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
