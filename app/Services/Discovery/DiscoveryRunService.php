<?php

namespace App\Services\Discovery;

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\DiscoveryRun;
use App\Models\GenerationProfile;
use App\Models\Source;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use App\Models\SourceArticle;

class DiscoveryRunService
{
    public function __construct(
        private readonly DiscoveryArticleValidator $articleValidator,
    ) {
    }

    public function create(
        GenerationProfile $profile,
        Source $source,
        Category $category,
        Country|CountryGroup $geo,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $generationRunId = null,
    ): DiscoveryRun {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException(
                'Discovery window_from must be earlier than window_to.'
            );
        }

        return DiscoveryRun::create([
            'generation_run_id' => $generationRunId,
            'generation_profile_id' => $profile->id,
            'source_id' => $source->id,
            'category_id' => $category->id,
            'geo_type' => $this->geoType($geo),
            'geo_id' => $geo->id,
            'window_from' => $from,
            'window_to' => $to,
            'status' => 'pending',
        ]);
    }



    public function markRunning(DiscoveryRun $run): DiscoveryRun
    {
        if ($run->status !== 'pending') {
            throw new InvalidArgumentException(
                'Only a pending Discovery Run can be started.'
            );
        }

        $startedAt = now();

        $attributes = [
            'status' => 'running',
            'started_at' => $startedAt,
            'error_message' => null,
        ];

        if ($run->generation_run_id !== null) {
            $freshnessMinutes = $run->generationProfile->freshness_minutes;

            $attributes['window_from'] = $startedAt->copy()
                ->subMinutes($freshnessMinutes);

            $attributes['window_to'] = $startedAt->copy();
        }

        $run->update($attributes);

        return $run->refresh();
    }

    public function storeArticle(
        DiscoveryRun $run,
        array $article,
    ): SourceArticle {
        $sourceArticle = SourceArticle::firstOrCreate(
            [
                'source_id' => $run->source_id,
                'url_hash' => $article['url_hash'],
            ],
            [
                'url' => $article['url'],
                'canonical_url' => $article['canonical_url'],
                'title' => $article['title'],
                'published_at' => $article['published_at'],
                'summary' => $article['summary'] ?? null,
                'status' => 'discovered',
            ],
        );

        $run->sourceArticles()->syncWithoutDetaching([
            $sourceArticle->id,
        ]);

        return $sourceArticle;
    }

    public function processArticles(
        DiscoveryRun $run,
        array $articles,
    ): DiscoveryRun {
        if ($run->status !== 'running') {
            throw new InvalidArgumentException(
                'Articles can only be processed for a running Discovery Run.'
            );
        }

        $foundCount = count($articles);
        $acceptedCount = 0;
        $rejectedCount = 0;

        foreach ($articles as $article) {
            if (!is_array($article)) {
                $rejectedCount++;
                continue;
            }

            $validated = $this->articleValidator->validate(
                $article,
                $run,
            );

            if ($validated === null) {
                $rejectedCount++;
                continue;
            }

            $this->storeArticle($run, $validated);

            $acceptedCount++;
        }

        $run->update([
            'found_count' => $foundCount,
            'accepted_count' => $acceptedCount,
            'rejected_count' => $rejectedCount,
        ]);

        return $run->refresh();
    }

    public function markCompleted(
        DiscoveryRun $run,
        array $usage = [],
    ): DiscoveryRun {
        if ($run->status !== 'running') {
            throw new InvalidArgumentException(
                'Only a running Discovery Run can be completed.'
            );
        }

        $run->update([
            'status' => 'completed',
            'finished_at' => now(),
            'google_search_count' => (int) ($usage['google_search_count'] ?? 0),
            'input_tokens' => (int) ($usage['total_input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['total_output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ]);

        return $run->refresh();
    }

    public function markFailed(
        DiscoveryRun $run,
        string $errorMessage,
    ): DiscoveryRun {
        if ($run->status !== 'running') {
            throw new InvalidArgumentException(
                'Only a running Discovery Run can be failed.'
            );
        }

        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $errorMessage,
        ]);

        return $run->refresh();
    }

    private function geoType(Country|CountryGroup $geo): string
    {
        return match (true) {
            $geo instanceof Country => 'country',
            $geo instanceof CountryGroup => 'country_group',
        };
    }
}
