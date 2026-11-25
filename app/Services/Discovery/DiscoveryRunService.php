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
use Illuminate\Support\Facades\DB;
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

    /**
     * Start or resume a Discovery Run.
     *
     * The discovery window is defined at creation time
     * and remains unchanged throughout the entire run.
     *
     * Counters are preserved when an existing run is resumed.
     */
    public function markRunning(DiscoveryRun $run): DiscoveryRun
    {
        $run->refresh();

        if ($run->status !== 'pending') {
            throw new InvalidArgumentException(
                'Only a pending Discovery Run can be started.'
            );
        }

        $attributes = [
            'status' => 'running',
            'error_message' => null,
            'finished_at' => null,
        ];

        if ($run->started_at === null) {
            $attributes['started_at'] = now();

            $attributes['found_count'] = 0;
            $attributes['accepted_count'] = 0;
            $attributes['rejected_count'] = 0;
        }

        $run->update($attributes);

        return $run->refresh();
    }

    /**
     * Persist an article and attach it to the Discovery Run.
     *
     * Existing articles are reused rather than duplicated.
     */
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
     * Validate and persist one article.
     *
     * An article already associated with this run
     * is not counted again when crawling resumes.
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

        DB::transaction(function () use ($run, $validated): void {
            $existingArticle = SourceArticle::query()
                ->where('source_id', $run->source_id)
                ->where('url_hash', $validated['url_hash'])
                ->first();

            $alreadyAttached = $existingArticle !== null
                && $run->sourceArticles()
                    ->whereKey($existingArticle->id)
                    ->exists();

            $this->storeArticle($run, $validated);

            if (!$alreadyAttached) {
                $run->increment('found_count');
                $run->increment('accepted_count');
            }
        });

        return true;
    }

    /**
     * Process a batch of articles.
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

    /**
     * Pause a Discovery Run while preserving progress.
     *
     * The queue itself remains managed by
     * SourceDiscoveryService.
     */
    public function markPending(DiscoveryRun $run): DiscoveryRun
    {
        $run->refresh();

        if ($run->status !== 'running') {
            throw new InvalidArgumentException(
                'Only a running Discovery Run can be paused.'
            );
        }

        $run->update([
            'status' => 'pending',
            'finished_at' => null,
        ]);

        return $run->refresh();
    }

    /**
     * Complete only when the crawler reports
     * that no work remains.
     */
    public function markCompleted(
        DiscoveryRun $run,
        array $usage = [],
        bool $crawlComplete = false,
    ): DiscoveryRun {
        $run->refresh();

        if ($run->status !== 'running') {
            throw new InvalidArgumentException(
                'Only a running Discovery Run can be completed.'
            );
        }

        if (!$crawlComplete) {
            throw new InvalidArgumentException(
                'Discovery cannot be completed while crawling is unfinished.'
            );
        }

        $run->update([
            'status' => 'completed',
            'finished_at' => now(),
            'error_message' => null,
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
        $run->refresh();

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
