<?php

namespace App\Services\Events;

use App\Models\DiscoveryRun;
use InvalidArgumentException;
use Throwable;

class EventProcessingService
{
    public function __construct(
        private readonly EventExtractionService $extraction,
        private readonly EventPersistenceService $persistence,
    ) {
    }

    public function process(DiscoveryRun $run): DiscoveryRun
    {

        $claimed = DiscoveryRun::query()
            ->whereKey($run->id)
            ->where('status', 'completed')
            ->whereIn('events_status', ['pending', 'failed'])
            ->update([
                'events_status' => 'running',
                'events_started_at' => now(),
                'events_finished_at' => null,
                'events_error_message' => null,
            ]);

        if ($claimed !== 1) {
            throw new InvalidArgumentException(
                'Discovery Run is not eligible for Event Processing.'
            );
        }

        $run->refresh();


        try {
            $extractionResult = $this->extraction->extract($run);

            $persistenceResult = $this->persistence->persist(
                $run,
                $extractionResult['events']
            );

            $usage = $extractionResult['usage'] ?? [];

            $run->update([
                'events_status' => 'completed',
                'events_count' =>
                    $persistenceResult['created_count'],
                'events_existing_count' =>
                    $persistenceResult['existing_count'],
                'events_finished_at' => now(),
                'events_error_message' => null,
                'events_input_tokens' =>
                    (int) ($usage['total_input_tokens'] ?? 0),
                'events_output_tokens' =>
                    (int) ($usage['total_output_tokens'] ?? 0),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'events_status' => 'failed',
                'events_finished_at' => now(),
                'events_error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        $run->refresh();

        $run->setRelation(
            'processedEvents',
            collect($persistenceResult['events'])->unique('id')->values()
        );

        return $run;
    }
}
