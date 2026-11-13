<?php

namespace App\Services\Generation;

use App\Models\Country;
use App\Models\GenerationProfile;
use App\Models\GenerationRun;
use App\Models\Source;
use App\Services\Discovery\DiscoveryRunService;
use App\Jobs\ExecuteDiscoveryRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GenerationRunService
{
    public function __construct(
        private readonly GenerationSourceSelector $sourceSelector,
        private readonly DiscoveryRunService $discoveryRunService,
    ) {
    }

    public function preview(GenerationProfile $profile): array
    {
        $combinations = $this->buildCombinations($profile);

        return [
            'sources_count' => $combinations
                ->pluck('source.id')
                ->unique()
                ->count(),

            'discovery_runs_count' => $combinations->count(),

            'combinations' => $combinations,
        ];
    }

    public function create(GenerationProfile $profile): GenerationRun
    {
        $combinations = $this->buildCombinations($profile);

        if ($combinations->isEmpty()) {
            throw new InvalidArgumentException(
                'No matching active sources, categories or GEO found.'
            );
        }

        $windowTo = now();
        $windowFrom = $windowTo->copy()->subMinutes(
            $profile->freshness_minutes
        );

        return DB::transaction(function () use (
            $profile,
            $combinations,
            $windowFrom,
            $windowTo
        ) {
            $generationRun = GenerationRun::create([
                'generation_profile_id' => $profile->id,
                'status' => 'pending',
                'sources_count' => $combinations
                    ->pluck('source.id')
                    ->unique()
                    ->count(),
                'discovery_runs_count' => $combinations->count(),
            ]);

            foreach ($combinations as $combination) {
                $this->discoveryRunService->create(
                    profile: $profile,
                    source: $combination['source'],
                    category: $combination['category'],
                    geo: $combination['geo'],
                    from: $windowFrom,
                    to: $windowTo,
                    generationRunId: $generationRun->id,
                );
            }

            return $generationRun->refresh();
        });
    }

    public function dispatch(GenerationRun $generationRun): int
    {
        return DB::transaction(function () use ($generationRun) {
            $run = GenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($generationRun->id);

            if ($run->status !== 'pending') {
                throw new InvalidArgumentException(
                    'Only pending generation runs can be dispatched.'
                );
            }

            $discoveryRuns = $run->discoveryRuns()
                ->where('status', 'pending')
                ->get();

            if ($discoveryRuns->isEmpty()) {
                throw new InvalidArgumentException(
                    'No pending Discovery Runs found.'
                );
            }

            $run->update([
                'status' => 'running',
                'started_at' => now(),
            ]);

            foreach ($discoveryRuns as $discoveryRun) {
                ExecuteDiscoveryRun::dispatch(
                    $discoveryRun->id
                )->afterCommit();
            }

            return $discoveryRuns->count();
        });
    }

    public function syncProgress(GenerationRun $generationRun): GenerationRun
    {
        return DB::transaction(function () use ($generationRun) {
            $run = GenerationRun::query()
                ->lockForUpdate()
                ->findOrFail($generationRun->id);

            $stats = $run->discoveryRuns()
                ->selectRaw("
                    COUNT(*) as total_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    COALESCE(SUM(accepted_count), 0) as articles_count,
                    COALESCE(SUM(google_search_count), 0) as search_count,
                    COALESCE(SUM(input_tokens), 0) as discovery_input_tokens,
                    COALESCE(SUM(output_tokens), 0) as discovery_output_tokens,
                    COALESCE(SUM(total_tokens), 0) as discovery_total_tokens,
                    SUM(CASE
                        WHEN status = 'completed' AND events_status = 'completed'
                        THEN 1 ELSE 0 END
                    ) as completed_events_runs,
                    SUM(CASE
                        WHEN status = 'completed'
                            AND events_status = 'completed'
                            AND markets_dispatched_at IS NOT NULL
                        THEN 1 ELSE 0 END
                    ) as completed_market_dispatch_runs,
                    SUM(CASE WHEN events_status = 'failed' THEN 1 ELSE 0 END) as failed_events_runs,
                    COALESCE(SUM(events_count), 0) as events_count,
                    COALESCE(SUM(events_input_tokens), 0) as events_input_tokens,
                    COALESCE(SUM(events_output_tokens), 0) as events_output_tokens
                ")
                ->first();

            // Use the pivot explicitly to avoid ONLY_FULL_GROUP_BY errors.
            $marketStats = DB::table('market_generation_runs as mgr')
                ->join(
                    'generation_run_market_generation_run as link',
                    'link.market_generation_run_id',
                    '=',
                    'mgr.id'
                )
                ->where('link.generation_run_id', $run->id)
                ->selectRaw("
                    COUNT(*) as total_count,
                    SUM(CASE WHEN mgr.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN mgr.status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    COALESCE(SUM(mgr.markets_count), 0) as markets_count,
                    COALESCE(SUM(
                        CASE WHEN mgr.generation_run_id = ?
                        THEN mgr.input_tokens ELSE 0 END
                    ), 0) as input_tokens,
                    COALESCE(SUM(
                        CASE WHEN mgr.generation_run_id = ?
                        THEN mgr.output_tokens ELSE 0 END
                    ), 0) as output_tokens
                ", [$run->id, $run->id])
                ->first();

            $total = (int) $stats->total_count;
            $completed = (int) $stats->completed_count;
            $failed = (int) $stats->failed_count;
            $completedEvents = (int) $stats->completed_events_runs;
            $failedEvents = (int) $stats->failed_events_runs;
            $completedMarketDispatchRuns = (int) $stats->completed_market_dispatch_runs;

            $marketTotal = (int) $marketStats->total_count;
            $marketCompleted = (int) $marketStats->completed_count;
            $marketFailed = (int) $marketStats->failed_count;

            $allDiscoveryFinished = $total > 0
                && ($completed + $failed) === $total;

            $allEventsFinished = $completed
                === ($completedEvents + $failedEvents);

            $allMarketDispatchFinished = $completedMarketDispatchRuns
                === $completedEvents;

            $allMarketsFinished = $allMarketDispatchFinished
                && ($marketCompleted + $marketFailed) === $marketTotal;

            $hasFailed = $failed > 0
                || $failedEvents > 0
                || $marketFailed > 0;

            $finished = $allDiscoveryFinished
                && $allEventsFinished
                && $allMarketsFinished;

            $marketInputTokens = (int) $marketStats->input_tokens;
            $marketOutputTokens = (int) $marketStats->output_tokens;

            $inputTokens = (int) $stats->discovery_input_tokens
                + (int) $stats->events_input_tokens
                + $marketInputTokens;

            $outputTokens = (int) $stats->discovery_output_tokens
                + (int) $stats->events_output_tokens
                + $marketOutputTokens;

            $attributes = [
                'discovery_runs_count' => $total,
                'completed_discovery_runs_count' => $completed,
                'failed_discovery_runs_count' => $failed,
                'articles_count' => (int) $stats->articles_count,
                'events_count' => (int) $stats->events_count,
                'markets_count' => (int) $marketStats->markets_count,
                'google_search_count' => (int) $stats->search_count,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => (int) $stats->discovery_total_tokens
                    + (int) $stats->events_input_tokens
                    + (int) $stats->events_output_tokens
                    + $marketInputTokens
                    + $marketOutputTokens,
            ];

            if ($run->status !== 'pending') {
                $attributes['status'] = $finished
                    ? ($hasFailed ? 'failed' : 'completed')
                    : 'running';

                $attributes['finished_at'] = $finished
                    ? ($run->finished_at ?? now())
                    : null;
            }

            $run->update($attributes);

            return $run->refresh();
        });
    }

    private function buildCombinations(
        GenerationProfile $profile
    ): Collection {
        $profile->load([
            'categories',
            'countries',
            'countryGroups.countries',
        ]);

        $sources = $this->sourceSelector->select($profile);

        $combinations = collect();

        foreach ($sources as $source) {
            $categories = $source->categories
                ->whereIn(
                    'id',
                    $profile->categories->pluck('id')
                )
                ->where('is_active', true);

            foreach ($categories as $category) {
                foreach ($this->matchingGeos($source, $profile) as $geo) {
                    $combinations->push([
                        'source' => $source,
                        'category' => $category,
                        'geo' => $geo,
                    ]);
                }
            }
        }

        return $combinations->unique(function ($item) {
            return implode(':', [
                $item['source']->id,
                $item['category']->id,
                $item['geo'] instanceof Country
                    ? 'country'
                    : 'country_group',
                $item['geo']->id,
            ]);
        })->values();
    }

    private function matchingGeos(
        Source $source,
        GenerationProfile $profile
    ): Collection {
        // Все страны, выбранные в профиле напрямую.
        $profileCountryIds = $profile->countries
            ->where('is_active', true)
            ->pluck('id');

        // Добавляем страны из групп профиля.
        foreach ($profile->countryGroups as $group) {
            if (!$group->is_active) {
                continue;
            }

            $profileCountryIds = $profileCountryIds->merge(
                $group->countries
                    ->where('is_active', true)
                    ->pluck('id')
            );
        }

        $profileCountryIds = $profileCountryIds->unique();

        // Все страны, привязанные к источнику напрямую.
        $sourceCountryIds = $source->countries
            ->where('is_active', true)
            ->pluck('id');

        // Добавляем страны из групп источника.
        foreach ($source->countryGroups as $group) {
            if (!$group->is_active) {
                continue;
            }

            $sourceCountryIds = $sourceCountryIds->merge(
                $group->countries
                    ->where('is_active', true)
                    ->pluck('id')
            );
        }

        $sourceCountryIds = $sourceCountryIds->unique();

        // Только страны, разрешённые и профилем, и источником.
        $matchingCountryIds = $profileCountryIds
            ->intersect($sourceCountryIds)
            ->unique()
            ->values();

        // Каждый GEO теперь конкретная страна, не группа.
        return Country::query()
            ->whereIn('id', $matchingCountryIds->all())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
