<?php

namespace App\Services\Discovery;

use App\Jobs\ProcessDiscoveryEvents;
use App\Models\DiscoveryRun;
use App\Services\Generation\GenerationRunService;
use Throwable;

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
    ): DiscoveryRun {
        $run = $this->runService->markRunning($run);

        try {
            $result = $this->sourceDiscovery->discover(
                source: $run->source,
                category: $run->category,
                geo: $run->resolveGeo(),
                from: $run->window_from,
                to: $run->window_to,
                onArticle: function (array $article) use ($run): void {
                    $this->runService->processArticle(
                        $run,
                        $article,
                    );
                },
            );

            // Articles are already persisted during crawling.
            // Do not call processArticles() again.

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

        ProcessDiscoveryEvents::dispatch(
            discoveryRunId: $run->id,
        );

        return $run;
    }
}
