<?php

namespace App\Services\Discovery;

use App\Jobs\ExecuteDiscoveryRun;
use App\Jobs\ProcessDiscoveryEvents;
use App\Models\DiscoveryRun;
use App\Models\SourceArticle;
use App\Services\Generation\GenerationRunService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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
        string $discoveryMode = 'full',
        bool $extractEvents = true,
        bool $generateMarkets = true,
    ): DiscoveryRun {
        if (!in_array($discoveryMode, ['full', 'existing'], true)) {
            throw new InvalidArgumentException(
                'Invalid discovery mode.'
            );
        }

        if ($generateMarkets && !$extractEvents) {
            throw new InvalidArgumentException(
                'Market generation requires event extraction.'
            );
        }

        // Protect the automatic Generation Run workflow.
        if (
            $run->generation_run_id !== null
            && (
                $discoveryMode !== 'full'
                || !$extractEvents
                || !$generateMarkets
            )
        ) {
            throw new InvalidArgumentException(
                'Automatic Generation Runs require the full workflow.'
            );
        }

        $run = $this->runService->markRunning($run);

        try {
            // Reuse previously stored articles.
            $reusedCount = $this->reuseStoredArticles($run);

            Log::info('Discovery started or resumed', [
                'discovery_run_id' => $run->id,
                'source_id' => $run->source_id,
                'window_from' => $run->window_from->toDateTimeString(),
                'window_to' => $run->window_to->toDateTimeString(),
                'reused_articles' => $reusedCount,
                'discovery_mode' => $discoveryMode,
                'extract_events' => $extractEvents,
                'generate_markets' => $generateMarkets,
            ]);

            $usage = [];

            // Crawl websites only in full discovery mode.
            if ($discoveryMode === 'full') {
                $result = $this->sourceDiscovery->discover(
                    source: $run->source,
                    category: $run->category,
                    geo: $run->resolveGeo(),
                    from: $run->window_from,
                    to: $run->window_to,
                    onArticle: function (array $article) use ($run): bool {
                        return $this->runService->processArticle(
                            $run,
                            $article,
                        );
                    },
                );

                $raw = $result['raw_response'] ?? [];

                // Preserve progress and resume unfinished crawling.
                if (!(bool) ($raw['crawl_complete'] ?? false)) {
                    $run = $this->runService->markPending($run);

                    $nextAttemptAt = (int) (
                        $raw['next_attempt_at'] ?? time()
                    );

                    $job = ExecuteDiscoveryRun::dispatch(
                        discoveryRunId: $run->id,
                        discoveryMode: $discoveryMode,
                        extractEvents: $extractEvents,
                        generateMarkets: $generateMarkets,
                    );

                    if ($nextAttemptAt > time()) {
                        $job->delay(
                            Carbon::createFromTimestamp($nextAttemptAt)
                        );
                    }

                    return $run;
                }

                $usage = $result['usage'] ?? [];
            }

            // Complete discovery.
            // In existing mode, crawling is intentionally skipped.
            $run = $this->runService->markCompleted(
                run: $run,
                usage: $usage,
                crawlComplete: true,
            );

            Log::info('Discovery completed', [
                'discovery_run_id' => $run->id,
                'source_id' => $run->source_id,
                'discovery_mode' => $discoveryMode,
                'found_count' => $run->found_count,
                'accepted_count' => $run->accepted_count,
                'rejected_count' => $run->rejected_count,
                'extract_events' => $extractEvents,
                'generate_markets' => $generateMarkets,
            ]);

        } catch (Throwable $e) {
            if ($run->fresh()?->status === 'running') {
                $run = $this->runService->markFailed(
                    $run,
                    $e->getMessage(),
                );
            }

            if ($run->generation_run_id !== null) {
                $this->generationRunService->syncProgress(
                    $run->generationRun,
                );
            }

            throw $e;
        }

        // Synchronize the parent generation progress.
        if ($run->generation_run_id !== null) {
            $this->generationRunService->syncProgress(
                $run->generationRun,
            );
        }

        // Start event extraction only when requested.
        if ($extractEvents) {
            ProcessDiscoveryEvents::dispatch(
                discoveryRunId: $run->id,
                generateMarkets: $generateMarkets,
            );
        }

        return $run;
    }

    /**
     * Reuse previously stored articles from the same source
     * whose publication dates match the fixed Discovery window.
     *
     * Articles already attached to this run are excluded.
     * Processing in chunks avoids loading all articles into memory.
     */
    private function reuseStoredArticles(DiscoveryRun $run): int
    {
        $reusedCount = 0;

        SourceArticle::query()
            ->where('source_id', $run->source_id)
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [
                $run->window_from,
                $run->window_to,
            ])
            ->whereNotIn(
                'id',
                $run->sourceArticles()
                    ->select('source_articles.id')
                    ->getQuery()
            )
            ->chunkById(
                200,
                function ($articles) use ($run, &$reusedCount): void {
                    foreach ($articles as $article) {
                        $accepted = $this->runService->processArticle(
                            $run,
                            [
                                'url' => $article->url,
                                'title' => $article->title,
                                'published_at' => $article->published_at
                                instanceof \DateTimeInterface
                                    ? $article->published_at->format(DATE_ATOM)
                                    : (string) $article->published_at,
                                'summary' => $article->summary,
                                'content' => $article->content,
                            ],
                        );

                        if ($accepted) {
                            $reusedCount++;
                        }
                    }
                },
            );

        return $reusedCount;
    }
}
