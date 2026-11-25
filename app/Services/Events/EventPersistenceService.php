<?php

namespace App\Services\Events;

use App\Models\DiscoveryRun;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class EventPersistenceService
{
    public function __construct(
        private readonly EventDeduplicationService $deduplication,
        private readonly EventDeduplicationKey $deduplicationKey,
    ) {
    }

    public function persist(
        DiscoveryRun $run,
        array $events
    ): array {
        if ($run->status !== 'completed') {
            throw new InvalidArgumentException(
                'Events can only be saved from a completed Discovery Run.'
            );
        }

        $emptyUsage = [
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_tokens' => 0,
        ];

        if ($events === []) {
            return [
                'events' => [],
                'created_count' => 0,
                'existing_count' => 0,
                'attached_articles_count' => 0,
                'usage' => $emptyUsage,
                'deduplication_requests_count' => 0,
            ];
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
         * Phase 1: validate and normalize all extracted events.
         */
        $preparedEvents = [];

        foreach (array_values($events) as $index => $data) {
            $this->validateEvent(
                $data,
                $allowedArticleIds
            );

            $title = trim($data['title']);

            $description = trim($data['description']);

            $eventAt = $data['event_at'] !== null
                ? CarbonImmutable::parse($data['event_at'])
                    ->utc()
                    ->toIso8601String()
                : null;

            $eventDate = $data['event_date'];

            $key = $this->deduplicationKey->make(
                (int) $run->category_id,
                $countryId,
                $title,
                $eventAt,
                $eventDate
            );

            $preparedEvents['new_' . $index] = [
                'title' => $title,
                'description' => $description,
                'event_type' => $data['event_type'],
                'event_date' => $eventDate,
                'event_at' => $eventAt,
                'article_ids' => array_values(
                    array_unique($data['article_ids'])
                ),
                'deduplication_key' => $key,
            ];
        }

        /*
         * Phase 2: run batched semantic deduplication.
         *
         * All external API requests are executed
         * outside the database transaction.
         */
        Log::info(
            'Event persistence deduplication started',
            [
                'discovery_run_id' => $run->id,
                'events_count' => count($preparedEvents),
            ]
        );

        $deduplicationResult = $this->deduplication->checkMany(
            events: array_values($preparedEvents),
            categoryId: (int) $run->category_id,
            countryId: $countryId,
            discoveryRunId: (int) $run->id
        );

        $decisions = $deduplicationResult['decisions'] ?? [];

        $usage = $deduplicationResult['usage']
            ?? $emptyUsage;

        $requestsCount = (int) (
            $deduplicationResult['requests_count'] ?? 0
        );

        if (count($decisions) !== count($preparedEvents)) {
            throw new RuntimeException(
                'Incomplete event deduplication result.'
            );
        }

        /*
         * Phase 3: validate decisions before saving.
         *
         * Uncertain decisions must not silently merge
         * unrelated events or create possible duplicates.
         */
        foreach ($preparedEvents as $id => $data) {
            $decision = $decisions[$id] ?? null;

            if (!is_array($decision)) {
                throw new RuntimeException(
                    'Missing deduplication decision for ' . $id
                );
            }

            $type = $decision['decision'] ?? null;

            if ($type === 'uncertain') {
                Log::warning(
                    'Uncertain event deduplication decision',
                    [
                        'discovery_run_id' => $run->id,
                        'incoming_id' => $id,
                        'title' => $data['title'],
                        'reason' => $decision['reason'] ?? null,
                    ]
                );

                throw new RuntimeException(
                    'Event deduplication is uncertain: '
                    . ($decision['reason'] ?? $id)
                );
            }

            if (!in_array(
                $type,
                ['new', 'existing'],
                true
            )) {
                throw new RuntimeException(
                    'Invalid event deduplication decision.'
                );
            }

            if ($type === 'new') {
                if (($decision['event_id'] ?? null) !== null) {
                    throw new RuntimeException(
                        'New event must not have a target ID.'
                    );
                }

                continue;
            }

            $targetId = $decision['event_id'] ?? null;

            if (!is_string($targetId)) {
                throw new RuntimeException(
                    'Invalid duplicate event reference.'
                );
            }

            if (preg_match('/^db_[1-9]\d*$/', $targetId)) {
                continue;
            }

            if (
                $targetId === $id
                || !isset($preparedEvents[$targetId])
                || ($decisions[$targetId]['decision'] ?? null)
                !== 'new'
            ) {
                throw new RuntimeException(
                    'Invalid canonical incoming event reference.'
                );
            }
        }

        /*
         * Phase 4: group article references by canonical event.
         */
        $groups = [];

        foreach ($preparedEvents as $id => $data) {
            $decision = $decisions[$id];

            $targetId = $decision['decision'] === 'new'
                ? $id
                : $decision['event_id'];

            if (!isset($groups[$targetId])) {
                $groups[$targetId] = [
                    'article_ids' => [],
                    'occurrences' => 0,
                ];
            }

            $groups[$targetId]['article_ids'] =
                array_values(
                    array_unique(
                        array_merge(
                            $groups[$targetId]['article_ids'],
                            $data['article_ids']
                        )
                    )
                );

            $groups[$targetId]['occurrences']++;
        }

        /*
         * Phase 5: save all canonical events atomically.
         *
         * No external API calls inside the transaction.
         */
        $result = DB::transaction(function () use (
            $run,
            $preparedEvents,
            $groups,
            $countryId,
            $locale
        ) {
            $createdCount = 0;
            $existingCount = 0;
            $attachedCount = 0;
            $savedEvents = [];

            foreach ($groups as $targetId => $group) {
                /*
                 * Existing database event.
                 */
                if (str_starts_with($targetId, 'db_')) {
                    $eventId = (int) substr(
                        $targetId,
                        3
                    );

                    $event = Event::query()
                        ->where('category_id', $run->category_id)
                        ->where('country_id', $countryId)
                        ->findOrFail($eventId);

                    $existingCount += $group['occurrences'];
                } else {
                    /*
                     * New canonical incoming event.
                     */
                    $data = $preparedEvents[$targetId];

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

                        $existingCount +=
                            $group['occurrences'] - 1;
                    } else {
                        $existingCount +=
                            $group['occurrences'];
                    }
                }

                /*
                 * Attach every supporting article from
                 * all matching incoming events.
                 */
                $articleIds = $group['article_ids'];

                $attached = $event->sourceArticles()
                    ->syncWithoutDetaching($articleIds);

                $attachedCount += count(
                    $attached['attached'] ?? []
                );

                /*
                 * Return unique persisted events.
                 */
                $savedEvents[$event->id] = $event;
            }

            /*
             * Link both new and existing events
             * to the current Discovery Run.
             */
            $eventIds = array_keys($savedEvents);

            if ($eventIds !== []) {
                $run->events()->syncWithoutDetaching(
                    $eventIds
                );
            }

            return [
                'events' => array_values($savedEvents),
                'created_count' => $createdCount,
                'existing_count' => $existingCount,
                'attached_articles_count' => $attachedCount,
            ];
        });

        Log::info(
            'Event persistence completed',
            [
                'discovery_run_id' => $run->id,
                'extracted_events_count' =>
                    count($preparedEvents),
                'unique_events_count' =>
                    count($result['events']),
                'created_count' =>
                    $result['created_count'],
                'existing_count' =>
                    $result['existing_count'],
                'attached_articles_count' =>
                    $result['attached_articles_count'],
                'deduplication_requests_count' =>
                    $requestsCount,
                'deduplication_usage' => $usage,
            ]
        );

        return [
            ...$result,
            'usage' => $usage,
            'deduplication_requests_count' => $requestsCount,
        ];
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
            if (
                !preg_match(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                    $data['event_at']
                )
            ) {
                throw new InvalidArgumentException(
                    'event_at must be ISO 8601 with timezone.'
                );
            }

            try {
                $eventAt = CarbonImmutable::parse(
                    $data['event_at']
                );
            } catch (\Throwable $e) {
                throw new InvalidArgumentException(
                    'Invalid event_at timestamp.',
                    previous: $e
                );
            }

            if (
                $data['event_date'] !== null
                && $eventAt->format('Y-m-d')
                !== $data['event_date']
            ) {
                throw new InvalidArgumentException(
                    'event_date does not match event_at local date.'
                );
            }

            $this->deduplicationKey->make(
                0,
                null,
                $data['title'],
                $data['event_at'],
                $data['event_date']
            );
        }
    }
}
