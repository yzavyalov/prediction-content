<?php

namespace App\Services\Events;

use App\Models\Category;
use App\Models\Country;
use App\Models\Event;
use App\Models\SourceArticle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ArticleEventPersistenceService
{
    public function __construct(
        private readonly EventDeduplicationService $deduplication,
        private readonly EventDeduplicationKey $deduplicationKey,
    ) {
    }

    public function persist(array $result): array
    {
        $articleIds = $result['article_ids'] ?? null;
        $events = $result['events'] ?? null;

        if (
            !is_array($articleIds)
            || !array_is_list($articleIds)
            || $articleIds === []
            || count($articleIds) !== count(array_unique($articleIds))
            || !is_array($events)
            || !array_is_list($events)
        ) {
            throw new InvalidArgumentException(
                'Invalid article extraction result.'
            );
        }

        foreach ($articleIds as $articleId) {
            if (!is_int($articleId) || $articleId < 1) {
                throw new InvalidArgumentException(
                    'Invalid source article ID.'
                );
            }
        }

        if (
            isset($result['articles_count'])
            && $result['articles_count'] !== count($articleIds)
        ) {
            throw new InvalidArgumentException(
                'Article count does not match extraction result.'
            );
        }

        if (
            isset($result['events_count'])
            && $result['events_count'] !== count($events)
        ) {
            throw new InvalidArgumentException(
                'Event count does not match extraction result.'
            );
        }

        $articles = SourceArticle::query()
            ->with('source.language')
            ->whereIn('id', $articleIds)
            ->get()
            ->keyBy('id');

        if ($articles->count() !== count($articleIds)) {
            throw new RuntimeException(
                'Some source articles do not exist.'
            );
        }

        foreach ($articles as $article) {
            if ($article->status !== 'discovered') {
                throw new RuntimeException(
                    'Article is not available for processing: '
                    . $article->id
                );
            }
        }

        $allowedArticleIds = array_fill_keys(
            $articleIds,
            true
        );

        $categoryIds = Category::query()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allowedCategoryIds = array_fill_keys(
            $categoryIds,
            true
        );

        /*
         * Countries use ISO 3166-1 alpha-2 codes
         * stored in the countries.iso2 column.
         */

        $countries = Country::query()
            ->get(['id', 'iso2']);

        $countryIdsByCode = [];

        foreach ($countries as $country) {
            $countryIdsByCode[
            strtoupper((string) $country->iso2)
            ] = (int) $country->id;
        }

        /*
         * Phase 1: validation and deduplication.
         *
         * Gemini requests happen outside the transaction.
         */

        $preparedEvents = [];
        $batchCandidates = [];
        $batchKeys = [];
        $deduplicationUsage = [];

        foreach ($events as $data) {
            $this->validateEvent(
                $data,
                $allowedArticleIds,
                $allowedCategoryIds
            );

            $categoryId = $data['category_id'];
            $countryCode = $data['country_code'];
            $countryId = null;

            if ($countryCode !== null) {
                if (!isset($countryIdsByCode[$countryCode])) {
                    throw new RuntimeException(
                        'Unknown country code: ' . $countryCode
                    );
                }

                $countryId = $countryIdsByCode[$countryCode];
            }

            $title = trim($data['title']);
            $description = trim($data['description']);

            $eventDate = $data['event_date'];
            $eventAt = $data['event_at'];

            $normalizedEventAt = $eventAt !== null
                ? CarbonImmutable::parse($eventAt)
                    ->utc()
                    ->toIso8601String()
                : null;

            $supportingArticleIds = array_values(
                array_unique($data['article_ids'])
            );

            $firstArticle = $articles->get(
                $supportingArticleIds[0]
            );

            $locale = $firstArticle?->source?->language?->code;

            if (!is_string($locale) || $locale === '') {
                throw new RuntimeException(
                    'Source article language is missing.'
                );
            }

            $eventData = [
                'title' => $title,
                'description' => $description,
                'event_date' => $eventDate,
                'event_at' => $normalizedEventAt,
                'event_type' => $data['event_type'],
            ];

            $key = $this->deduplicationKey->make(
                $categoryId,
                $countryId,
                $title,
                $normalizedEventAt,
                $eventDate
            );

            /*
             * Exact duplicate within the current batch.
             */

            if (isset($batchKeys[$key])) {
                $index = $batchKeys[$key];

                $preparedEvents[$index]['article_ids'] =
                    array_values(array_unique(array_merge(
                        $preparedEvents[$index]['article_ids'],
                        $supportingArticleIds
                    )));

                $preparedEvents[$index]['occurrences']++;

                continue;
            }

            /*
             * Compare against persisted database events.
             */

            $decision = $this->deduplication->check(
                $eventData,
                $categoryId,
                $countryId
            );

            $deduplicationUsage[] = $decision['usage'] ?? [];

            if ($decision['decision'] === 'uncertain') {
                throw new RuntimeException(
                    'Event deduplication is uncertain: '
                    . $decision['reason']
                );
            }

            if (!in_array(
                $decision['decision'],
                ['existing', 'new'],
                true
            )) {
                throw new RuntimeException(
                    'Invalid deduplication decision.'
                );
            }

            if ($decision['decision'] === 'existing') {
                $preparedIndex = count($preparedEvents);

                $preparedEvents[] = [
                    ...$eventData,
                    'category_id' => $categoryId,
                    'country_id' => $countryId,
                    'source_locale' => $locale,
                    'deduplication_key' => $key,
                    'article_ids' => $supportingArticleIds,
                    'decision' => 'existing',
                    'existing_event_id' => $decision['event_id'],
                    'occurrences' => 1,
                ];

                $batchKeys[$key] = $preparedIndex;

                continue;
            }

            /*
             * Compare against new events in this batch.
             * Only same-category, same-country candidates.
             */

            $compatibleCandidates = [];
            $candidateIndexes = [];

            foreach ($batchCandidates as $candidate) {
                if (
                    $candidate['category_id'] !== $categoryId
                    || $candidate['country_id'] !== $countryId
                ) {
                    continue;
                }

                $candidateIndexes[] =
                    $candidate['prepared_index'];

                $compatibleCandidates[] = [
                    'title' => $candidate['title'],
                    'description' => $candidate['description'],
                    'event_date' => $candidate['event_date'],
                    'event_at' => $candidate['event_at'],
                    'event_type' => $candidate['event_type'],
                ];
            }

            if ($compatibleCandidates !== []) {
                $batchDecision = $this->deduplication->checkBatch(
                    $eventData,
                    $compatibleCandidates
                );

                $deduplicationUsage[] =
                    $batchDecision['usage'] ?? [];

                if ($batchDecision['decision'] === 'uncertain') {
                    throw new RuntimeException(
                        'Batch event deduplication is uncertain: '
                        . $batchDecision['reason']
                    );
                }

                if ($batchDecision['decision'] === 'existing') {
                    $batchId = $batchDecision['event_id'];

                    $batchIndex = (int) substr(
                        $batchId,
                        strlen('batch_')
                    );

                    if (!isset($candidateIndexes[$batchIndex])) {
                        throw new RuntimeException(
                            'Unknown batch candidate index.'
                        );
                    }

                    $preparedIndex =
                        $candidateIndexes[$batchIndex];

                    $preparedEvents[$preparedIndex]['article_ids'] =
                        array_values(array_unique(array_merge(
                            $preparedEvents[$preparedIndex]['article_ids'],
                            $supportingArticleIds
                        )));

                    $preparedEvents[$preparedIndex]['occurrences']++;

                    $batchKeys[$key] = $preparedIndex;

                    continue;
                }

                if ($batchDecision['decision'] !== 'new') {
                    throw new RuntimeException(
                        'Invalid batch deduplication decision.'
                    );
                }
            }

            /*
             * New event.
             */

            $preparedIndex = count($preparedEvents);

            $preparedEvents[] = [
                ...$eventData,
                'category_id' => $categoryId,
                'country_id' => $countryId,
                'source_locale' => $locale,
                'deduplication_key' => $key,
                'article_ids' => $supportingArticleIds,
                'decision' => 'new',
                'existing_event_id' => null,
                'occurrences' => 1,
            ];

            $batchCandidates[] = [
                ...$eventData,
                'category_id' => $categoryId,
                'country_id' => $countryId,
                'prepared_index' => $preparedIndex,
            ];

            $batchKeys[$key] = $preparedIndex;
        }

        /*
         * Phase 2: atomic database persistence.
         *
         * No Gemini requests inside the transaction.
         */

        return DB::transaction(function () use (
            $articleIds,
            $preparedEvents,
            $deduplicationUsage
        ) {
            $lockedArticles = SourceArticle::query()
                ->whereIn('id', $articleIds)
                ->lockForUpdate()
                ->get();

            if ($lockedArticles->count() !== count($articleIds)) {
                throw new RuntimeException(
                    'Source articles changed during processing.'
                );
            }

            foreach ($lockedArticles as $article) {
                if ($article->status !== 'discovered') {
                    throw new RuntimeException(
                        'Source article was already processed: '
                        . $article->id
                    );
                }
            }

            $createdCount = 0;
            $existingCount = 0;
            $attachedCount = 0;
            $savedEvents = [];

            foreach ($preparedEvents as $data) {
                if ($data['decision'] === 'existing') {
                    $event = Event::query()->findOrFail(
                        $data['existing_event_id']
                    );

                    $existingCount += $data['occurrences'];
                } else {
                    $event = Event::firstOrCreate(
                        [
                            'deduplication_key' =>
                                $data['deduplication_key'],
                        ],
                        [
                            'category_id' => $data['category_id'],
                            'country_id' => $data['country_id'],
                            'title' => $data['title'],
                            'description' => $data['description'],
                            'status' => 'draft',
                            'event_date' => $data['event_date'],
                            'event_at' => $data['event_at'],
                            'event_type' => $data['event_type'],
                            'source_locale' => $data['source_locale'],
                        ]
                    );

                    if ($event->wasRecentlyCreated) {
                        $createdCount++;
                        $existingCount +=
                            $data['occurrences'] - 1;
                    } else {
                        $existingCount += $data['occurrences'];
                    }
                }

                $supportingArticleIds = $data['article_ids'];

                $existingArticleIds = $event->sourceArticles()
                    ->whereIn(
                        'source_articles.id',
                        $supportingArticleIds
                    )
                    ->pluck('source_articles.id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $newArticleIds = array_values(array_diff(
                    $supportingArticleIds,
                    $existingArticleIds
                ));

                if ($newArticleIds !== []) {
                    $event->sourceArticles()->syncWithoutDetaching(
                        $newArticleIds
                    );

                    $attachedCount += count($newArticleIds);
                }

                $savedEvents[] = $event;
            }

            /*
             * Mark every article in the batch as processed,
             * including articles with no extracted events.
             */

            SourceArticle::query()
                ->whereIn('id', $articleIds)
                ->update([
                    'status' => 'processed',
                ]);

            return [
                'articles_processed_count' => count($articleIds),
                'events' => $savedEvents,
                'created_count' => $createdCount,
                'existing_count' => $existingCount,
                'attached_articles_count' => $attachedCount,
                'deduplication_usage' => $deduplicationUsage,
            ];
        });
    }

    private function validateEvent(
        mixed $data,
        array $allowedArticleIds,
        array $allowedCategoryIds
    ): void {
        if (
            !is_array($data)
            || !is_string($data['title'] ?? null)
            || trim($data['title']) === ''
            || mb_strlen(trim($data['title'])) > 500
            || !is_string($data['description'] ?? null)
            || trim($data['description']) === ''
            || !is_int($data['category_id'] ?? null)
            || !isset(
                $allowedCategoryIds[$data['category_id']]
            )
            || !in_array(
                $data['event_type'] ?? null,
                ['future', 'observed', 'ongoing'],
                true
            )
            || !array_key_exists('country_code', $data)
            || !array_key_exists('event_date', $data)
            || !array_key_exists('event_at', $data)
            || !isset($data['article_ids'])
            || !is_array($data['article_ids'])
            || !array_is_list($data['article_ids'])
            || $data['article_ids'] === []
        ) {
            throw new InvalidArgumentException(
                'Invalid extracted event data.'
            );
        }

        $countryCode = $data['country_code'];

        if (
            $countryCode !== null
            && (
                !is_string($countryCode)
                || !preg_match('/^[A-Z]{2}$/', $countryCode)
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid country_code.'
            );
        }

        $eventDate = $data['event_date'];
        $eventAt = $data['event_at'];

        if ($eventDate !== null) {
            if (
                !is_string($eventDate)
                || !preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $eventDate
                )
            ) {
                throw new InvalidArgumentException(
                    'Invalid event_date format.'
                );
            }

            [$year, $month, $day] = array_map(
                'intval',
                explode('-', $eventDate)
            );

            if (!checkdate($month, $day, $year)) {
                throw new InvalidArgumentException(
                    'Invalid calendar date.'
                );
            }
        }

        if ($eventAt !== null) {
            if (
                !is_string($eventAt)
                || !preg_match(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                    $eventAt
                )
            ) {
                throw new InvalidArgumentException(
                    'Invalid event_at format.'
                );
            }

            try {
                $parsed = CarbonImmutable::parse($eventAt);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException(
                    'Invalid event_at timestamp.',
                    previous: $e
                );
            }

            if (
                $eventDate === null
                || $parsed->format('Y-m-d') !== $eventDate
            ) {
                throw new InvalidArgumentException(
                    'event_date and event_at do not match.'
                );
            }
        }

        foreach ($data['article_ids'] as $articleId) {
            if (
                !is_int($articleId)
                || !isset($allowedArticleIds[$articleId])
            ) {
                throw new InvalidArgumentException(
                    'Event references an article outside its batch.'
                );
            }
        }
    }
}
