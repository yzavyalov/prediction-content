<?php

namespace App\Services\Markets;

use App\Models\Event;
use App\Services\AI\GeminiService;
use JsonException;
use RuntimeException;

class MarketGenerationService
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly MarketValidationService $validation,
    ) {
    }

    public function generate(Event $event): array
    {
        if ($event->event_type === 'observed') {
            return [
                'markets' => [],
                'usage' => [],
            ];
        }

        if (
            $event->event_type === 'future'
            && $event->event_at !== null
            && $event->event_at->lessThanOrEqualTo(now('UTC'))
        ) {
            return [
                'markets' => [],
                'usage' => [],
            ];
        }

        $event->loadMissing('sourceArticles');

        $generatedAt = now('UTC');
        $defaultDeadline = $generatedAt->copy()->addDays(14);

        $context = [
            'current_datetime_utc' => $generatedAt->toIso8601String(),
            'default_deadline_utc' => $defaultDeadline->toIso8601String(),
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'event_type' => $event->event_type,
                'event_date' => $event->event_date?->format('Y-m-d'),
                'event_at' => $event->event_at?->toIso8601String(),
                'source_locale' => $event->source_locale,
            ],
            'articles' => $event->sourceArticles->map(
                fn ($article) => [
                    'id' => $article->id,
                    'title' => $article->title,
                    'summary' => $article->summary,
                    'url' => $article->canonical_url,
                    'published_at' => $article->published_at?->toIso8601String(),
                ]
            )->values()->all(),
        ];

        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );

        $prompt = <<<PROMPT
You are a professional prediction market designer.

Analyze the supplied event and supporting news articles.

Your goal is to generate multiple DISTINCT prediction markets
based on different objectively verifiable aspects of the event.

MARKET GENERATION RULES:

1. Generate several genuinely different markets when supported.
2. Explore different dimensions:
   - Event outcomes
   - Timing and deadlines
   - Quantitative results
   - Participants and decisions
   - Alternative scenarios
3. Never create multiple markets that merely rephrase the same question.
4. Do not invent facts, dates, participants, thresholds, or sources.
5. Do not generate markets with already known outcomes.
6. Every market must have at least two outcomes.
7. There is no fixed maximum number of outcomes.
8. Outcomes must be mutually exclusive and collectively exhaustive
   under the market's resolution rules.
9. Every market must have exactly one winning outcome.
10. Include objective and unambiguous resolution rules.
11. Explain how cancellations, postponements, and unavailable
    resolution information are handled.
12. Use ISO 8601 timestamps with timezone offsets.

13. The input contains default_deadline_utc, calculated by the
    application as 14 days after the generation time.

14. Every generated market must have a definite deadline.

15. If the event has a reliable future deadline explicitly
    supported by the supplied articles, use that deadline.
    Otherwise, use default_deadline_utc.

16. The market question must explicitly include its deadline,
    expressed as a calendar date and, where necessary, time.

17. The resolution rules must define exactly what must happen
    before the deadline for each outcome to win.

18. If the required event has not occurred by the deadline,
    the market must resolve to the appropriate negative
    outcome, provided this can be objectively verified.

19. Set finish_at to the selected deadline in ISO 8601 format.

20. Set resolution_at to the same deadline unless the supplied
    information establishes a later resolution date.

21. Do not invent event facts, participants, thresholds,
    official schedules, or source URLs.

22. Never generate a market whose outcome is already known.

23. Outcomes must be mutually exclusive and collectively
    exhaustive, with exactly one winning outcome.

24. Explain how cancellations, postponements, and unavailable
    resolution information are handled.

25. Return an empty markets array if no objectively
    resolvable markets can be generated.

LANGUAGE RULES:

16. Always generate content in the event's source_locale.
17. Always include English (en).
18. Identify countries directly involved in the event.
19. Include their applicable languages when identifiable.
20. Do not add languages solely because a country is mentioned
    incidentally in a news article.
21. Do not duplicate language codes.
22. Translate questions, descriptions, resolution rules,
    and every outcome title.
23. Preserve exactly the same meaning, thresholds, dates,
    and outcome structure across all languages.
24. Do not translate official names when doing so changes meaning.

OUTPUT RULES:

25. Return only valid JSON without Markdown fences.
26. Do not include explanatory text outside the JSON.
27. Use stable outcome codes that are independent of language.
28. Use null for unknown dates.
29. Each market must contain translations for every selected language.
30. Do not invent additional source URLs.

Return this JSON structure:

{
  "markets": [
    {
      "question": "Question in source language",
      "description": "Description in source language",
      "resolution_rules": "Rules in source language",
      "finish_at": null,
      "resolution_at": null,
      "outcomes": [
        {
          "code": "outcome_1",
          "title": "First outcome in source language"
        },
        {
          "code": "outcome_2",
          "title": "Second outcome in source language"
        }
      ],
      "translations": [
        {
          "locale": "en",
          "question": "English question",
          "description": "English description",
          "resolution_rules": "English resolution rules",
          "outcomes": [
            {
              "code": "outcome_1",
              "title": "First outcome in English"
            },
            {
              "code": "outcome_2",
              "title": "Second outcome in English"
            }
          ]
        }
      ]
    }
  ]
}

Include the source language in translations as well.
Every translation must contain the same outcome codes.

Input data:

{$json}
PROMPT;

        $response = $this->gemini->generateText(
            model: (string) config('services.gemini.models.generation'),
            prompt: $prompt,
        );

        $text = $this->extractModelText($response);

        if ($text === '') {
            throw new RuntimeException(
                'Gemini returned an empty market generation response.'
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
                'Gemini returned invalid market generation JSON.',
                previous: $e
            );
        }

        if (
            !is_array($data)
            || !isset($data['markets'])
            || !is_array($data['markets'])
            || !array_is_list($data['markets'])
        ) {
            throw new RuntimeException(
                'Gemini returned an invalid markets structure.'
            );
        }

        return [
            'markets' => $this->validation->validate(
                $event,
                $data['markets']
            ),
            'usage' => $response['usage'] ?? [],
        ];
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
