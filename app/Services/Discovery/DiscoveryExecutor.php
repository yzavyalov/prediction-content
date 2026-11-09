<?php

namespace App\Services\Discovery;

use App\Models\DiscoveryRun;
use Throwable;
use App\Services\Generation\GenerationRunService;

class DiscoveryExecutor
{
    public function __construct(
        private readonly DiscoveryRunService $runService,
        private readonly SourceDiscoveryService $sourceDiscovery,
        private readonly GenerationRunService $generationRunService,
    ) {
    }


    public function execute(
        DiscoveryRun $run,
        int $maxResults = 10,
    ): DiscoveryRun {
        $run = $this->runService->markRunning($run);

        try {
            $result = $this->sourceDiscovery->discover(
                source: $run->source,
                category: $run->category,
                geo: $run->resolveGeo(),
                from: $run->window_from,
                to: $run->window_to,
                maxResults: $maxResults,
            );

            $run = $this->runService->processArticles(
                $run,
                $result['articles'],
            );

            $run = $this->runService->markCompleted(
                $run,
                $result['usage'] ?? [],
            );
        } catch (Throwable $e) {
            if ($run->fresh()->status === 'running') {
                $run = $this->runService->markFailed(
                    $run,
                    $e->getMessage(),
                );
            }

            if ($run->generation_run_id !== null) {
                $this->generationRunService->syncProgress(
                    $run->generationRun
                );
            }

            throw $e;
        }

        if ($run->generation_run_id !== null) {
            $this->generationRunService->syncProgress(
                $run->generationRun
            );
        }

        return $run;
    }

}
