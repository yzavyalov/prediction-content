<?php

namespace App\Services\Events;

use App\Models\Category;
use App\Models\SourceArticle;
use App\Services\AI\GeminiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use JsonException;
use RuntimeException;

class ArticleEventExtractionService
{
    public function __construct(
        private readonly GeminiService $gemini,
    ) {
    }

    public function extract(
        int $sourceId = 2,
        int $limit = 20,
    ): array {
        if ($limit < 1 || $limit > 30) {
            throw new RuntimeException(
                'Article batch size must be between 1 and 30.'
            );
        }

        $articles = SourceArticle::query()
            ->with('source.language')
            ->where('source_id', $sourceId)
            ->where('status', 'discovered')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($articles->isEmpty()) {
            return [
                'articles_count' => 0,
                'article_ids' => [],
                'unavailable_article_ids' => [],
                'events_count' => 0,
                'events' => [],
                'usage' => [],
            ];
        }

        $categories = Category::query()
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($categories->isEmpty()) {
            throw new RuntimeException(
                'No event categories are configured.'
            );
        }


        $requiresUrlContext = $articles->contains(
            fn ($article) =>
                !is_string($article->content)
                || trim($article->content) === ''
        );

        $model = (string) config(
            'services.gemini.models.extraction'
        );

        $prompt = $this->buildPrompt(
            $articles,
            $categories
        );

        $response = $requiresUrlContext
            ? $this->gemini->generateWithUrlContext(
                model: $model,
                prompt: $prompt,
            )
            : $this->gemini->generateText(
                model: $model,
                prompt: $prompt,
            );


        $allowedArticleIds = $this->getAvailableArticleIds(
            $response,
            $articles
        );

        $unavailableArticleIds = $articles
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => isset($allowedArticleIds[$id]))
            ->values()
            ->all();

        $events = $this->parseResponse(
            $response,
            $articles,
            $categories,
            $allowedArticleIds
        );

        return [
            'articles_count' => $articles->count(),
            'article_ids' => $articles->pluck('id')->all(),
            'unavailable_article_ids' => $unavailableArticleIds,
            'events_count' => count($events),
            'events' => $events,
            'usage' => $response['usage'] ?? [],
        ];
    }

    private function buildPrompt(
        Collection $articles,
        Collection $categories,
    ): string {

        $articleData = $articles->map(
            function ($article) {
                $content = is_string($article->content)
                    ? trim($article->content)
                    : '';

                return [
                    'article_id' => $article->id,
                    'title' => $article->title,
                    'summary' => $article->summary,
                    'content' => $content !== ''
                        ? mb_substr($content, 0, 12000)
                        : null,
                    'url' => $article->canonical_url,
                    'published_at' =>
                        $article->published_at?->toIso8601String(),
                    'source_locale' =>
                        $article->source?->language?->code,
                ];
            }
        )->values()->all();


        $context = [
            'current_datetime_utc' =>
                now('UTC')->toIso8601String(),

            'categories' => $categories->map(
                fn ($category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ]
            )->values()->all(),

            'articles' => $articleData,
        ];

        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );

        return <<<PROMPT
You are an event extraction engine for a prediction market platform.

Analyze ONLY the supplied news articles.


ARTICLE CONTENT RULES:

Each article contains a title, summary, content, and URL.

1. If content is present and non-empty, use it as the
   primary source of facts for that article.

2. If content is null or empty, use the URL Context tool
   to open the article URL and read its contents.

3. Do not assume that a URL has been successfully read
   merely because it was included in the input.

4. If URL Context cannot retrieve an article, do not
   extract events from that article based only on its
   title or summary.

5. Do not invent missing facts, dates, participants,
   decisions, or outcomes.

6. Do not use external websites or Google Search.

7. Extract events only from article content that was
   supplied or successfully retrieved.

8. If no articles contain sufficient verified information,
   return {"events": []}.


Your task is to identify distinct, verifiable real-world events.

An event is a specific occurrence, development, decision,
announcement, action, or scheduled activity.

Extract past, ongoing, and future events.

GENERAL RULES:

1. Use only facts supported by the supplied articles.
2. Never invent events, participants, dates, times, or sources.
3. Do not present speculation or opinions as confirmed facts.
4. Merge articles describing the same underlying event.
5. One article may support multiple distinct events.
6. One event may be supported by multiple articles.
7. Use only article_id values provided in the input.
8. Do not generate prediction market questions.
9. Keep event titles concise, factual, and neutral.
10. Return only valid JSON without Markdown fences.
11. Do not confuse an announcement with the future event
    being announced.
12. Separate an announcement and a scheduled event only
    when both are independently significant and verifiable.
13. Avoid extracting trivial statements as separate events.
14. Do not treat an expected outcome as a confirmed result.

EVENT TYPES:

- future:
  A scheduled or expected event that has not happened yet.

- observed:
  An event that has already happened.

- ongoing:
  An event or process currently in progress.

Use current_datetime_utc to distinguish event types.

Do not classify an event as future merely because
an older article describes it using future tense.

When the event date is known, use it to check whether
the event is in the past or future.

Do not invent an event status when the available
information is insufficient.

EVENT DATE AND TIME:

Return two separate fields:

event_date:
- Calendar date in YYYY-MM-DD format.
- Use null if the calendar date cannot be established.
- If an exact calendar date is explicitly stated,
  preserve it even when the time is unknown.
- Never invent a calendar date.

event_at:
- Exact event timestamp in ISO 8601 format
  with an explicit UTC offset or Z.
- Use null when the exact time or timezone is unknown.
- Never invent a time or timezone.
- If only the calendar date is known,
  return event_date and set event_at to null.
- If the exact date and time are known,
  return both event_date and event_at.
- Both fields must represent the same calendar date
  in the event's local timezone when both are present.
- Never use the article publication time as event time.
- Never substitute the current date for an unknown date.
- Resolve relative dates only when the publication date
  and article context make the date unambiguous.
- For ongoing events, event_date may represent
  the known start date.
- For an observed event, event_date represents
  the date on which the event occurred.

Example:
An article published in November 2026 states that
a conference will take place on March 16, 2027,
but does not specify its start time.

Return:
"event_date": "2027-03-16",
"event_at": null,
"event_type": "future"

CATEGORIES:

- Every event must have exactly one primary category.
- Select category_id exclusively from the provided categories.
- Choose the most specific relevant category.
- Do not invent new categories.
- Classify based on the event itself,
  not the source website.
- An event's category is independent of its GEO.

GEO:

- Determine the geographic relevance of the event.
- country_code must be an ISO 3166-1 alpha-2 code
  or null when a specific country cannot be established.
- Do not automatically assign Romania merely because
  the article comes from a Romanian news website.
- International events may have country_code null.
- Do not confuse event location with article language.

LANGUAGE:

- Write event titles and descriptions in the language
  specified by the supporting articles' source_locale.
- If supporting articles use different source locales,
  use the locale of the first supporting article.
- Preserve official names of institutions, people,
  organizations, and legal documents where appropriate.

OUTPUT STRUCTURE:

{
  "events": [
    {
      "title": "Factual event title",
      "description": "Factual event description",
      "category_id": 11,
      "event_type": "future",
      "country_code": "RO",
      "event_date": "2027-03-16",
      "event_at": null,
      "article_ids": [1, 2]
    }
  ]
}

If no suitable events are found, return:

{"events": []}

INPUT DATA:

{$json}
PROMPT;
    }

    private function parseResponse(
        array $response,
        Collection $articles,
        Collection $categories,
        array $allowedArticleIds,
    ): array {
        $text = $this->extractModelText($response);

        if ($text === '') {
            throw new RuntimeException(
                'Gemini returned an empty event extraction response.'
            );
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


        $categoryNames = $categories
            ->mapWithKeys(
                fn ($category) => [
                    (int) $category->id => $category->name
                ]
            )
            ->all();

        $events = [];
        $knownArticleIds = $articles->pluck('id')->mapWithKeys(
            fn ($id) => [(int) $id => true]
        )->all();

        foreach ($data['events'] as $event) {
            // Unknown IDs remain validation errors; unavailable known IDs
            // cause only the affected event to be skipped.
            if (
                is_array($event)
                && is_array($event['article_ids'] ?? null)
                && array_is_list($event['article_ids'])
                && $event['article_ids'] !== []
            ) {
                $unavailable = false;
                foreach ($event['article_ids'] as $articleId) {
                    if (is_int($articleId) && !isset($knownArticleIds[$articleId])) {
                        throw new RuntimeException('Gemini referenced an unknown article ID.');
                    }
                    if (is_int($articleId) && !isset($allowedArticleIds[$articleId])) {
                        $unavailable = true;
                    }
                }
                if ($unavailable) {
                    continue;
                }
            }

            if (
                !is_array($event)
                || !is_string($event['title'] ?? null)
                || trim($event['title']) === ''
                || !is_string($event['description'] ?? null)
                || trim($event['description']) === ''
                || !is_int($event['category_id'] ?? null)
                || !array_key_exists(
                    $event['category_id'],
                    $categoryNames
                )
                || !in_array(
                    $event['event_type'] ?? null,
                    ['future', 'observed', 'ongoing'],
                    true
                )
                || !array_key_exists('country_code', $event)
                || !array_key_exists('event_date', $event)
                || !array_key_exists('event_at', $event)
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
                    || !isset($allowedArticleIds[$id])
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

            $eventDate = $event['event_date'];

            if ($eventDate !== null) {
                if (
                    !is_string($eventDate)
                    || !preg_match(
                        '/^\d{4}-\d{2}-\d{2}$/',
                        $eventDate
                    )
                ) {
                    throw new RuntimeException(
                        'Gemini returned an invalid event_date format.'
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

            $eventAt = $event['event_at'];

            if ($eventAt !== null) {
                if (
                    !is_string($eventAt)
                    || !preg_match(
                        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                        $eventAt
                    )
                ) {
                    throw new RuntimeException(
                        'Gemini returned an invalid event_at format.'
                    );
                }

                try {
                    $parsedEventAt = CarbonImmutable::parse(
                        $eventAt
                    );
                } catch (\Throwable $e) {
                    throw new RuntimeException(
                        'Gemini returned an invalid event timestamp.',
                        previous: $e
                    );
                }

                if ($eventDate === null) {
                    throw new RuntimeException(
                        'event_date is required when event_at is present.'
                    );
                }

                if (
                    $parsedEventAt->format('Y-m-d')
                    !== $eventDate
                ) {
                    throw new RuntimeException(
                        'event_date does not match the event_at local date.'
                    );
                }
            }

            $countryCode = $event['country_code'];

            if (
                $countryCode !== null
                && (
                    !is_string($countryCode)
                    || !preg_match(
                        '/^[A-Z]{2}$/',
                        $countryCode
                    )
                )
            ) {
                throw new RuntimeException(
                    'Gemini returned an invalid country code.'
                );
            }

            $events[] = [
                'title' => trim($event['title']),
                'description' => trim($event['description']),
                'category_id' => $event['category_id'],
                'category_name' =>
                    $categoryNames[$event['category_id']],
                'event_type' => $event['event_type'],
                'country_code' => $countryCode,
                'event_date' => $eventDate,
                'event_at' => $eventAt,
                'article_ids' => array_values($articleIds),
            ];
        }

        return $events;
    }


    private function getAvailableArticleIds(
        array $response,
        Collection $articles,
    ): array {
        $successfulUrls = [];

        foreach ($response['steps'] ?? [] as $step) {
            if (($step['type'] ?? null) !== 'url_context_result') {
                continue;
            }

            foreach ($step['result'] ?? [] as $result) {
                if (
                    is_array($result)
                    && is_string($result['url'] ?? null)
                    && ($result['status'] ?? null) === 'success'
                ) {
                    $successfulUrls[$result['url']] = true;
                }
            }
        }

        $availableIds = [];
        foreach ($articles as $article) {
            $hasContent = is_string($article->content)
                && trim($article->content) !== '';

            $url = $article->canonical_url;
            if (
                $hasContent
                || (is_string($url) && isset($successfulUrls[$url]))
            ) {
                $availableIds[(int) $article->id] = true;
            }
        }

        return $availableIds;
    }

    private function extractModelText(array $response): string
    {
        foreach (
            array_reverse($response['steps'] ?? []) as $step
        ) {
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
