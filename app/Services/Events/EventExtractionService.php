<?php

namespace App\Services\Events;

use App\Models\DiscoveryRun;
use App\Services\AI\GeminiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class EventExtractionService
{
    public function __construct(
        private readonly GeminiService $gemini,
    ) {
    }

    public function extract(DiscoveryRun $run): array
    {
        if ($run->status !== 'completed') {
            throw new InvalidArgumentException(
                'Events can only be extracted from a completed Discovery Run.'
            );
        }

        $articles = $run->sourceArticles()
            ->orderBy('source_articles.id')
            ->get();

        if ($articles->isEmpty()) {
            return [
                'events' => [],
                'usage' => [],
                'articles_count' => 0,
            ];
        }

        $response = $this->gemini->generateText(
            model: (string) config('services.gemini.models.extraction'),
            prompt: $this->buildPrompt($run, $articles),
        );

        $events = $this->parseResponse($response, $articles);

        return [
            'events' => $events,
            'usage' => $response['usage'] ?? [],
            'articles_count' => $articles->count(),
        ];
    }

    private function buildPrompt(
        DiscoveryRun $run,
        Collection $articles,
    ): string {
        $geo = $run->resolveGeo();

        $articleData = $articles->map(function ($article) {
            return [
                'article_id' => $article->id,
                'title' => $article->title,
                'summary' => $article->summary,
                'url' => $article->canonical_url,
                'published_at' => $article->published_at?->toIso8601String(),
            ];
        })->values()->all();

        $context = [
            'category' => $run->category->name,
            'geo' => $geo->name,
            'source_locale' => $run->source->language->code,
            'current_datetime_utc' => now('UTC')->toIso8601String(),
            'articles' => $articleData,
        ];

        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );


        return <<<PROMPT
You are an event extraction engine for a prediction market platform.

Analyze ONLY the supplied news articles.

Identify distinct, factual real-world events supported by the
article titles and summaries.

EVENT TYPES:

- future: An event scheduled or expected to occur in the future.
- ongoing: An event or process that has started but has not concluded.
- observed: An event that has already occurred.

RULES:

1. Use only facts explicitly supported by the supplied articles.
2. Never invent events, dates, times, participants, outcomes, or sources.
3. Extract future, ongoing, and observed events.
4. Do not require an exact date or time for an event to be extracted.
5. event_date must use YYYY-MM-DD when the calendar date is known.
6. If the calendar date is unknown, use null for event_date.
7. event_at must be an ISO 8601 timestamp with an explicit timezone.
8. Use null for event_at when the exact time or timezone is unknown.
9. Never invent a time such as midnight or noon.
10. When both event_date and event_at are provided, they must
    represent the same local calendar date.
11. Do not use the article publication date as the event date
    unless the article explicitly establishes that relationship.
12. Resolve relative dates only when the publication date
    and article context make the date unambiguous.
13. Use current_datetime_utc to distinguish future and past events.
14. Classify an event as ongoing only when the supplied information
    supports that it has started and has not concluded.
15. Do not treat predictions or expectations as confirmed results.
16. Do not create events from generic opinions or commentary.
17. Merge articles describing the same real-world occurrence.
18. One article may support multiple distinct events.
19. One event may be supported by multiple articles.
20. Use only article_id values provided in the input.
21. Every event must have at least one supporting article_id.
22. Do not generate prediction market questions.
23. Do not infer that an event's outcome is unknown merely
    because the article does not mention the outcome.
24. Return an empty events array when no factual events
    can be established from the supplied information.
25. Return only valid JSON without Markdown fences.

LANGUAGE AND GEO:

26. Write event titles and descriptions in the language
    specified by source_locale.
27. Do not automatically translate titles or descriptions
    into English.
28. Preserve official names of people, institutions,
    organizations, and documents when appropriate.
29. GEO determines geographic relevance, not output language.
30. Keep titles concise, factual, and neutral.

OUTPUT FORMAT:

{
  "events": [
    {
      "title": "Concise factual event title",
      "description": "Factual event description",
      "event_type": "future",
      "event_date": null,
      "event_at": null,
      "article_ids": [1]
    }
  ]
}

The output example is illustrative only.
Never copy its event or article ID unless supported by the input.

INPUT DATA:

{$json}
PROMPT;

    }

    private function parseResponse(
        array $response,
        Collection $articles,
    ): array {
        $text = $this->extractModelText($response);

        if ($text === '') {
            throw new RuntimeException(sprintf(
                'Gemini empty response. Status: %s; Steps: %s',
                (string) ($response['status'] ?? 'unknown'),
                json_encode(array_map(
                    fn ($step) => [
                        'type' => $step['type'] ?? null,
                        'keys' => array_keys($step),
                        'content_types' => array_map(
                            fn ($content) => $content['type'] ?? null,
                            $step['content'] ?? []
                        ),
                    ],
                    $response['steps'] ?? []
                ))
            ));
        }

        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace(
                '/^```(?:json)?\s*|\s*```$/i',
                '',
                $text
            );

            $text = trim($text);
        }

        try {
            $data = json_decode(
                $text,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Gemini returned invalid event extraction JSON.',
                previous: $e
            );
        }

        if (
            !is_array($data)
            || !isset($data['events'])
            || !is_array($data['events'])
            || !array_is_list($data['events'])
        ) {
            throw new RuntimeException(
                'Gemini returned an invalid events structure.'
            );
        }

        $allowedIds = $articles
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $events = [];

        foreach ($data['events'] as $event) {
            if (
                !is_array($event)
                || !is_string($event['title'] ?? null)
                || trim($event['title']) === ''
                || !is_string($event['description'] ?? null)
                || !isset($event['article_ids'])
                || !is_array($event['article_ids'])
                || !array_is_list($event['article_ids'])
            ) {
                throw new RuntimeException(
                    'Gemini returned an invalid event entry.'
                );
            }

            $articleIds = [];

            foreach ($event['article_ids'] as $id) {
                if (
                    !is_int($id)
                    || !isset($allowedIds[$id])
                ) {
                    throw new RuntimeException(
                        'Gemini referenced an unknown article ID.'
                    );
                }

                $articleIds[$id] = $id;
            }

            if ($articleIds === []) {
                throw new RuntimeException(
                    'An extracted event has no supporting articles.'
                );
            }

            $eventType = $event['event_type'] ?? null;

            if (!in_array($eventType, ['future', 'ongoing', 'observed'], true)) {
                throw new RuntimeException(
                    'Gemini returned an invalid event_type.'
                );
            }

            $eventDate = $event['event_date'] ?? null;

            $eventAt = $event['event_at'] ?? null;

            if ($eventDate !== null) {
                if (
                    !is_string($eventDate)
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)
                ) {
                    throw new RuntimeException(
                        'Gemini returned an invalid event_date.'
                    );
                }

                [$year, $month, $day] = array_map(
                    'intval',
                    explode('-', $eventDate)
                );

                if (!checkdate($month, $day, $year)) {
                    throw new RuntimeException(
                        'Gemini returned an invalid calendar date.'
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
                    throw new RuntimeException(
                        'Gemini returned an invalid event_at.'
                    );
                }

                try {
                    $scheduledAt = CarbonImmutable::parse($eventAt);
                } catch (\Throwable $e) {
                    throw new RuntimeException(
                        'Gemini returned an invalid event timestamp.',
                        previous: $e
                    );
                }

                if (
                    $eventDate !== null
                    && $scheduledAt->format('Y-m-d') !== $eventDate
                ) {
                    throw new RuntimeException(
                        'event_date does not match event_at local date.'
                    );
                }

                $now = CarbonImmutable::now('UTC');

                if ($eventType === 'future' && $scheduledAt->lessThanOrEqualTo($now)) {
                    continue;
                }

                if ($eventType === 'observed' && $scheduledAt->greaterThan($now)) {
                    continue;
                }
            }


            if ($eventDate !== null && $eventAt === null) {
                $today = CarbonImmutable::now('UTC')->format('Y-m-d');

                if ($eventType === 'future' && $eventDate < $today) {
                    continue;
                }

                if ($eventType === 'observed' && $eventDate > $today) {
                    continue;
                }
            }


            $events[] = [
                'title' => trim($event['title']),
                'description' => trim($event['description']),
                'event_type' => $eventType,
                'event_date' => $eventDate,
                'event_at' => $eventAt,
                'article_ids' => array_values($articleIds),
            ];

        }

        return $events;
    }

    private function extractModelText(array $response): string
    {
        foreach (array_reverse($response['steps'] ?? []) as $step) {
            if (($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ($step['content'] ?? [] as $content) {
                if (
                    ($content['type'] ?? null) === 'text'
                    && isset($content['text'])
                    && is_string($content['text'])
                ) {
                    return $content['text'];
                }
            }
        }

        return '';
    }
}
