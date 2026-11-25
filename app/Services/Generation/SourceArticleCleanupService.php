<?php

namespace App\Services\Generation;

use App\Models\GenerationRun;
use App\Models\SourceArticle;
use Illuminate\Support\Facades\DB;

class SourceArticleCleanupService
{
    /**
     * Delete articles without events after generation finishes.
     *
     * Articles referenced by unfinished Discovery Runs
     * are preserved.
     */
    public function cleanup(GenerationRun $generationRun): int
    {
        return DB::transaction(function () use ($generationRun): int {
            $discoveryRunIds = $generationRun
                ->discoveryRuns()
                ->pluck('id');

            if ($discoveryRunIds->isEmpty()) {
                return 0;
            }

            $deleted = 0;

            SourceArticle::query()
                ->whereDoesntHave('events')
                ->whereHas('discoveryRuns', function ($query) use ($discoveryRunIds) {
                    $query->whereIn(
                        'discovery_runs.id',
                        $discoveryRunIds
                    );
                })
                ->whereDoesntHave('discoveryRuns', function ($query) {
                    $query->where(function ($query) {
                        $query->whereIn(
                            'discovery_runs.status',
                            ['pending', 'running']
                        )->orWhere(function ($query) {
                            $query->where('discovery_runs.status', 'completed')
                                ->where(function ($query) {
                                    $query->whereNull('discovery_runs.events_status')
                                        ->orWhere(
                                            'discovery_runs.events_status',
                                            '!=',
                                            'completed'
                                        );
                                });
                        });
                    });
                })
                ->select('source_articles.id')
                ->chunkById(200, function ($articles) use (&$deleted) {
                    $ids = $articles->pluck('id')->all();

                    $deleted += SourceArticle::query()
                        ->whereIn('id', $ids)
                        ->whereDoesntHave('events')
                        ->delete();
                });

            return $deleted;
        });
    }
}
