<?php

namespace App\Services\Markets;

use App\Models\MarketGenerationRun;
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
            ->update([
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
                'error_message' => null,
            ]);

        if ($claimed !== 1) {
            throw new InvalidArgumentException(
                'Market Generation Run is not eligible for processing.'
            );
        }

        $run->refresh();

        try {
            $event = $run->event;

            $generationResult = $this->generation->generate($event);

            $persistenceResult = $this->persistence->persist(
                $event,
                $generationResult['markets'],
                $run->generation_run_id
            );

            $usage = $generationResult['usage'] ?? [];

            $run->update([
                'status' => 'completed',
                'markets_count' => $persistenceResult['created_count'],
                'finished_at' => now(),
                'error_message' => null,
                'input_tokens' =>
                    (int) ($usage['total_input_tokens'] ?? 0),
                'output_tokens' =>
                    (int) ($usage['total_output_tokens'] ?? 0),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $run->refresh();
    }
}
