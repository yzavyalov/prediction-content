<?php

namespace App\Jobs;

use App\Models\MarketGenerationRun;
use App\Services\Generation\GenerationRunService;
use App\Services\Markets\MarketProcessingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessEventMarkets implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $marketGenerationRunId
    ) {
    }

    public function uniqueId(): string
    {
        return 'market-generation-run:' . $this->marketGenerationRunId;
    }

    public function handle(
        MarketProcessingService $processor,
        GenerationRunService $generationRunService,
    ): void {
        $run = MarketGenerationRun::findOrFail(
            $this->marketGenerationRunId
        );

        if ($run->status !== 'pending') {
            return;
        }

        try {
            $processor->process($run);
        } finally {
            $run->refresh();

            $generationRuns = $run->generationRuns()
                ->get();

            foreach ($generationRuns as $generationRun) {
                $generationRunService->syncProgress(
                    $generationRun
                );
            }
        }
    }
}
