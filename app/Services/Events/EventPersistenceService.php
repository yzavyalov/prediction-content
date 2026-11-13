<?php

namespace App\Services\Events;

use App\Models\DiscoveryRun;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class EventPersistenceService
{
    public function __construct(
        private readonly EventDeduplicationService $deduplication,
        private readonly EventDeduplicationKey $deduplicationKey,
    ) {
    }

    public function persist(DiscoveryRun $run, array $events): array
    {
        if ($run->status !== 'completed') {
            throw new InvalidArgumentException(
                'Events can only be saved from a completed Discovery Run.'
            );
        }

        $allowedArticleIds = $run->sourceArticles()
            ->pluck('source_articles.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $allowedArticleIds = array_fill_keys(
            $allowedArticleIds,
            true
        );

        $countryId = $run->geo_type === 'country'
            ? (int) $run->geo_id
            : null;

        $locale = (string) $run->source->language->code;

        /*
         * Phase 1: validate and deduplicate.
         *
         * All Gemini calls happen outside the transaction.
         */
        $preparedEvents = [];
        $batchCandidates = [];
        $batchKeys = [];

        foreach ($events as $data) {
            $this->validateEvent($data, $allowedArticleIds);

            $title = trim($data['title']);
            $description = trim($data['description']);

            $eventAt = $data['event_at'] !== null
                ? CarbonImmutable::parse($data['event_at'])
                    ->utc()
                    ->toIso8601String()
                : null;

            $eventDate = $data['event_date'] ?? null;
            $eventType = $data['event_type'] ?? null;

            $key = $this->deduplicationKey->make(
                (int) $run->category_id,
                $countryId,
                $title,
                $eventAt,
                $eventDate
            );

            $articleIds = array_values(array_unique(
                $data['article_ids']
            ));

            /*
             * Exact duplicate within the current batch.
             */
            if (isset($batchKeys[$key])) {
                $index = $batchKeys[$key];

                $preparedEvents[$index]['article_ids'] =
                    array_values(array_unique(array_merge(
                        $preparedEvents[$index]['article_ids'],
                        $articleIds
                    )));

                $preparedEvents[$index]['occurrences']++;

                continue;
            }

            $eventData = [
                'title' => $title,
                'description' => $description,
                'event_type' => $eventType,
                'event_date' => $eventDate,
                'event_at' => $eventAt,
            ];

            /*
             * Compare against persisted database events.
             */
            $decision = $this->deduplication->check(
                $eventData,
                (int) $run->category_id,
                $countryId
            );

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
                    'Invalid event deduplication decision.'
                );
            }

            /*
             * Existing database event.
             */
            if ($decision['decision'] === 'existing') {
                $preparedEvents[] = [
                    ...$eventData,
                    'deduplication_key' => $key,
                    'article_ids' => $articleIds,
                    'decision' => 'existing',
                    'existing_event_id' => $decision['event_id'],
                    'occurrences' => 1,
                ];

                continue;
            }

            /*
             * Compare new events within the current batch.
             */
            if ($batchCandidates !== []) {
                $batchDecision = $this->deduplication->checkBatch(
                    $eventData,
                    $batchCandidates
                );

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

                    if (!isset($batchCandidates[$batchIndex])) {
                        throw new RuntimeException(
                            'Unknown prepared batch event.'
                        );
                    }

                    $preparedIndex =
                        $batchCandidates[$batchIndex]['prepared_index'];

                    $preparedEvents[$preparedIndex]['article_ids'] =
                        array_values(array_unique(array_merge(
                            $preparedEvents[$preparedIndex]['article_ids'],
                            $articleIds
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
             * A genuinely new event.
             */
            $preparedIndex = count($preparedEvents);

            $preparedEvents[] = [
                ...$eventData,
                'deduplication_key' => $key,
                'article_ids' => $articleIds,
                'decision' => 'new',
                'existing_event_id' => null,
                'occurrences' => 1,
            ];

            $batchCandidates[] = [
                ...$eventData,
                'prepared_index' => $preparedIndex,
            ];

            $batchKeys[$key] = $preparedIndex;
        }

        /*
         * Phase 2: persist atomically.
         *
         * No external API requests inside this transaction.
         */
        return DB::transaction(function () use (
            $run,
            $preparedEvents,
            $countryId,
            $locale
        ) {
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
                            'category_id' => $run->category_id,
                            'country_id' => $countryId,
                            'title' => $data['title'],
                            'description' => $data['description'],
                            'status' => 'draft',
                            'event_type' => $data['event_type'],
                            'event_date' => $data['event_date'],
                            'event_at' => $data['event_at'],
                            'source_locale' => $locale,
                        ]
                    );

                    if ($event->wasRecentlyCreated) {
                        $createdCount++;
                        $existingCount += $data['occurrences'] - 1;
                    } else {
                        $existingCount += $data['occurrences'];
                    }
                }

                /*
                 * Attach source articles to the Event.
                 */
                $articleIds = $data['article_ids'];

                $existingArticleIds = $event->sourceArticles()
                    ->whereIn('source_articles.id', $articleIds)
                    ->pluck('source_articles.id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $newArticleIds = array_values(array_diff(
                    $articleIds,
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
             * Link all saved Events to this Discovery Run.
             *
             * Includes both newly created and existing Events.
             * Duplicate relationships are not inserted.
             */
            $eventIds = collect($savedEvents)
                ->pluck('id')
                ->unique()
                ->values()
                ->all();

            if ($eventIds !== []) {
                $run->events()->syncWithoutDetaching($eventIds);
            }

            return [
                'events' => $savedEvents,
                'created_count' => $createdCount,
                'existing_count' => $existingCount,
                'attached_articles_count' => $attachedCount,
            ];
        });
    }

    private function validateEvent(
        mixed $data,
        array $allowedArticleIds
    ): void {
        if (
            !is_array($data)
            || !is_string($data['title'] ?? null)
            || trim($data['title']) === ''
            || mb_strlen(trim($data['title'])) > 500
            || !is_string($data['description'] ?? null)
            || !array_key_exists('event_at', $data)
            || (
                $data['event_at'] !== null
                && !is_string($data['event_at'])
            )
            || !isset($data['article_ids'])
            || !is_array($data['article_ids'])
            || !array_is_list($data['article_ids'])
            || $data['article_ids'] === []
        ) {
            throw new InvalidArgumentException(
                'Invalid event data.'
            );
        }


        if (
            !isset($data['event_type'])
            || !in_array(
                $data['event_type'],
                ['future', 'ongoing', 'observed'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid event_type.'
            );
        }

        if (
            !array_key_exists('event_date', $data)
            || (
                $data['event_date'] !== null
                && !is_string($data['event_date'])
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid event_date.'
            );
        }

        if ($data['event_date'] !== null) {
            if (
                !preg_match(
                    '/^\d{4}-\d{2}-\d{2}$/',
                    $data['event_date']
                )
            ) {
                throw new InvalidArgumentException(
                    'event_date must use YYYY-MM-DD format.'
                );
            }

            [$year, $month, $day] = array_map(
                'intval',
                explode('-', $data['event_date'])
            );

            if (!checkdate($month, $day, $year)) {
                throw new InvalidArgumentException(
                    'Invalid event_date calendar date.'
                );
            }
        }


        foreach ($data['article_ids'] as $articleId) {
            if (
                !is_int($articleId)
                || !isset($allowedArticleIds[$articleId])
            ) {
                throw new RuntimeException(
                    'Event references an article outside this Discovery Run.'
                );
            }
        }

        if ($data['event_at'] !== null) {
            $this->deduplicationKey->make(
                0,
                null,
                $data['title'],
                $data['event_at']
            );
        }
    }
}
