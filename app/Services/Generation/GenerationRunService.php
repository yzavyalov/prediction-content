<?php

namespace App\Services\Generation;

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\GenerationProfile;
use App\Models\GenerationRun;
use App\Models\Source;
use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use App\Jobs\ExecuteDiscoveryRun;

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
                    discoveryRunId: $discoveryRun->id,
                    maxResults: 10,
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
                COALESCE(SUM(input_tokens), 0) as input_tokens_count,
                COALESCE(SUM(output_tokens), 0) as output_tokens_count,
                COALESCE(SUM(total_tokens), 0) as total_tokens_count
            ")
                ->first();

            $total = (int) $stats->total_count;
            $completed = (int) $stats->completed_count;
            $failed = (int) $stats->failed_count;

            $finished = $total > 0
                && ($completed + $failed) === $total;

            $attributes = [
                'discovery_runs_count' => $total,
                'completed_discovery_runs_count' => $completed,
                'failed_discovery_runs_count' => $failed,
                'articles_count' => (int) $stats->articles_count,
                'google_search_count' => (int) $stats->search_count,
                'input_tokens' => (int) $stats->input_tokens_count,
                'output_tokens' => (int) $stats->output_tokens_count,
                'total_tokens' => (int) $stats->total_tokens_count,
            ];

            if ($run->status !== 'pending') {
                $attributes['status'] = $finished
                    ? ($failed > 0 ? 'failed' : 'completed')
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
