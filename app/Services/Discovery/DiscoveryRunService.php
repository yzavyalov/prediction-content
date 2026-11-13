<?php

namespace App\Services\Discovery;

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\DiscoveryRun;
use App\Models\GenerationProfile;
use App\Models\Source;
use App\Models\SourceArticle;
use Carbon\CarbonInterface;
use InvalidArgumentException;

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
            'found_count' => 0,
            'accepted_count' => 0,
            'rejected_count' => 0,
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
        $content = $article['content'] ?? null;

        $content = is_string($content)
            ? trim($content)
            : null;

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
                'content' => $content !== '' ? $content : null,
                'status' => 'discovered',
            ],
        );

        // Fill missing content without overwriting existing text.
        if (
            empty($sourceArticle->content)
            && $content !== null
            && $content !== ''
        ) {
            $sourceArticle->update([
                'content' => $content,
            ]);
        }

        $run->sourceArticles()->syncWithoutDetaching([
            $sourceArticle->id,
        ]);

        return $sourceArticle;
    }


    /**
     * Validate and persist one discovered article immediately.
     *
     * Returns true when the article passes validation.
     */
    public function processArticle(
        DiscoveryRun $run,
        array $article,
    ): bool {
        if ($run->status !== 'running') {
            throw new InvalidArgumentException(
                'Articles can only be processed for a running Discovery Run.'
            );
        }

        $validated = $this->articleValidator->validate(
            $article,
            $run,
        );

        if ($validated === null) {
            $run->increment('found_count');
            $run->increment('rejected_count');

            return false;
        }

        $this->storeArticle($run, $validated);

        $run->increment('found_count');
        $run->increment('accepted_count');

        return true;
    }

    /**
     * Process a batch of articles.
     *
     * Counters accumulate instead of being reset after each batch.
     */
    public function processArticles(
        DiscoveryRun $run,
        array $articles,
    ): DiscoveryRun {
        if ($run->status !== 'running') {
            throw new InvalidArgumentException(
                'Articles can only be processed for a running Discovery Run.'
            );
        }

        foreach ($articles as $article) {
            if (!is_array($article)) {
                $run->increment('found_count');
                $run->increment('rejected_count');

                continue;
            }

            $this->processArticle($run, $article);
        }

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
            'google_search_count' => (int) (
                $usage['google_search_count'] ?? 0
            ),
            'input_tokens' => (int) (
                $usage['total_input_tokens'] ?? 0
            ),
            'output_tokens' => (int) (
                $usage['total_output_tokens'] ?? 0
            ),
            'total_tokens' => (int) (
                $usage['total_tokens'] ?? 0
            ),
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

    private function geoType(
        Country|CountryGroup $geo,
    ): string {
        return match (true) {
            $geo instanceof Country => 'country',
            $geo instanceof CountryGroup => 'country_group',
        };
    }
}
