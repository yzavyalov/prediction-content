<?php

namespace App\Jobs;

use App\Models\DiscoveryRun;
use App\Services\Events\EventProcessingService;
use App\Services\Generation\GenerationRunService;
use App\Services\Markets\MarketGenerationRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessDiscoveryEvents implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 600;

    public function __construct(
        public readonly int $discoveryRunId,
    ) {
    }

    /**
     * Prevent concurrent processing of the same Discovery Run.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'discovery-events-' . $this->discoveryRunId
            ))
                ->releaseAfter(60)
                ->expireAfter(900),
        ];
    }

    public function handle(
        EventProcessingService $processor,
        GenerationRunService $generationRunService,
        MarketGenerationRunService $marketGenerationRunService,
    ): void {
        $run = DiscoveryRun::findOrFail(
            $this->discoveryRunId
        );

        // Discovery must be completed.
        if ($run->status !== 'completed') {
            return;
        }

        // Market jobs have already been dispatched.
        if ($run->markets_dispatched_at !== null) {
            return;
        }

        // Do not process an Event extraction already running.
        if (!in_array(
            $run->events_status,
            ['pending', 'failed', 'completed'],
            true
        )) {
            return;
        }

        try {
            /*
             * Step 1: Load or extract Events.
             *
             * Completed Events are reused without another
             * Gemini extraction request.
             */
            if ($run->events_status === 'completed') {
                $events = $run->events()->get();
            } else {
                $processedRun = $processor->process($run);

                $events = $processedRun->getRelation(
                    'processedEvents'
                );
            }

            /*
             * Step 2: Create and dispatch Market generation jobs.
             *
             * MarketGenerationRunService handles existing
             * pending or running generation runs.
             */
            foreach ($events as $event) {
                if ($event->event_type === 'observed') {
                    continue;
                }

                $marketRun = $marketGenerationRunService->create(
                    event: $event,
                    generationRunId: $run->generation_run_id
                );

                if ($marketRun->status === 'pending') {
                    ProcessEventMarkets::dispatch(
                        marketGenerationRunId: $marketRun->id
                    );
                }
            }

            /*
             * Step 3: Mark Market dispatch as completed.
             *
             * Also applies when no Events were found.
             */
            $run->update([
                'markets_dispatched_at' => now(),
            ]);
        } finally {
            /*
             * Step 4: Synchronize Generation Run progress
             * even when an exception occurs.
             */
            if ($run->generation_run_id !== null) {
                $generationRunService->syncProgress(
                    $run->generationRun
                );
            }
        }
    }
}
