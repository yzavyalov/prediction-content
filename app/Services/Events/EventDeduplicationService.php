<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Services\AI\GeminiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class EventDeduplicationService
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly EventDeduplicationKey $deduplicationKey,
    ) {
    }

    public function check(
        array $eventData,
        int $categoryId,
        ?int $countryId
    ): array {
        $event = $this->normalizeEvent($eventData);

        $key = $this->deduplicationKey->make(
            $categoryId,
            $countryId,
            $event['title'],
            $event['event_at'],
            $event['event_date']
        );

        $exact = Event::query()
            ->where('deduplication_key', $key)
            ->first();

        if ($exact !== null) {
            return [
                'decision' => 'existing',
                'event_id' => $exact->id,
                'reason' => 'Exact deduplication key match.',
                'usage' => [],
            ];
        }

        $candidates = $this->findCandidates(
            $categoryId,
            $countryId,
            $event['event_date'],
            $event['event_at']
        );

        if ($candidates->isEmpty()) {
            return [
                'decision' => 'new',
                'event_id' => null,
                'reason' => 'No existing candidate events.',
                'usage' => [],
            ];
        }

        $candidateData = $candidates
            ->map(fn (Event $candidate) => [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'description' => $candidate->description,
                'event_date' => $candidate->event_date?->format('Y-m-d'),
                'event_at' => $candidate->event_at?->toIso8601String(),
                'event_type' => $candidate->event_type,
            ])
            ->values()
            ->all();

        return $this->compareWithGemini(
            $event,
            $candidateData,
            false
        );
    }

    public function checkBatch(
        array $eventData,
        array $preparedCandidates
    ): array {
        if ($preparedCandidates === []) {
            return [
                'decision' => 'new',
                'event_id' => null,
                'reason' => 'No prepared batch candidates.',
                'usage' => [],
            ];
        }

        $event = $this->normalizeEvent($eventData);

        $candidates = [];

        foreach ($preparedCandidates as $index => $candidate) {
            $normalized = $this->normalizeEvent($candidate);

            $candidates[] = [
                'id' => 'batch_' . $index,
                ...$normalized,
            ];
        }

        return $this->compareWithGemini(
            $event,
            $candidates,
            true
        );
    }

    private function normalizeEvent(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if ($title === '') {
            throw new InvalidArgumentException(
                'Event title is required for deduplication.'
            );
        }

        $eventDate = $data['event_date'] ?? null;
        $eventAt = $data['event_at'] ?? null;

        if ($eventDate !== null) {
            if (
                !is_string($eventDate)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)
            ) {
                throw new InvalidArgumentException(
                    'event_date must use YYYY-MM-DD format.'
                );
            }

            [$year, $month, $day] = array_map(
                'intval',
                explode('-', $eventDate)
            );

            if (!checkdate($month, $day, $year)) {
                throw new InvalidArgumentException(
                    'Invalid event_date calendar date.'
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
                    'event_at must be ISO 8601 with timezone.'
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
                $eventDate !== null
                && $parsed->format('Y-m-d') !== $eventDate
            ) {
                throw new InvalidArgumentException(
                    'event_date does not match event_at local date.'
                );
            }

            $eventAt = $parsed->utc()->toIso8601String();
        }

        $eventType = $data['event_type'] ?? null;

        if (
            $eventType !== null
            && !in_array(
                $eventType,
                ['future', 'observed', 'ongoing'],
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid event_type.'
            );
        }

        return [
            'title' => $title,
            'description' => $description,
            'event_date' => $eventDate,
            'event_at' => $eventAt,
            'event_type' => $eventType,
        ];
    }

    private function findCandidates(
        int $categoryId,
        ?int $countryId,
        ?string $eventDate,
        ?string $eventAt
    ): Collection {
        $query = Event::query()
            ->where('category_id', $categoryId)
            ->where('country_id', $countryId);

        if ($eventDate !== null) {
            $date = CarbonImmutable::parse($eventDate);

            $from = $date->subDays(14)->format('Y-m-d');
            $to = $date->addDays(14)->format('Y-m-d');

            $query->where(function ($q) use ($from, $to) {
                $q->whereBetween('event_date', [$from, $to])
                    ->orWhereNull('event_date');
            });
        } elseif ($eventAt !== null) {
            $date = CarbonImmutable::parse($eventAt)->utc();

            $query->where(function ($q) use ($date) {
                $q->whereBetween('event_at', [
                    $date->subDays(14)->toDateTimeString(),
                    $date->addDays(14)->toDateTimeString(),
                ])->orWhereNull('event_at');
            });
        }

        return $query
            ->orderByDesc('id')
            ->limit(30)
            ->get([
                'id',
                'title',
                'description',
                'event_date',
                'event_at',
                'event_type',
            ]);
    }

    private function compareWithGemini(
        array $event,
        array $candidates,
        bool $batch
    ): array {
        $json = json_encode(
            [
                'new_event' => $event,
                'existing_events' => $candidates,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_PRETTY_PRINT
            | JSON_THROW_ON_ERROR
        );

        $prompt = <<<PROMPT
You are a semantic event deduplication engine.

Determine whether the new event describes exactly
the SAME real-world occurrence as one of the candidates.

Compare event identity, not merely topic similarity.

RULES:

1. Compare participants, institutions, locations,
   actions, dates, and event descriptions.
2. Different headlines may describe the same occurrence.
3. Similar recurring events may be different occurrences.
4. Two events on different dates are not automatically
   identical, even when their titles match.
5. An announcement and the event being announced
   may be different real-world occurrences.
6. event_date represents the calendar date of an event.
7. event_at represents its exact timestamp, when known.
8. A missing date does not prove that events are identical.
9. event_type is useful context but does not alone
   determine event identity.
10. Return "existing" only when there is a clear match.
11. Return "new" when all candidates are clearly different.
12. Return "uncertain" when identity cannot be established.
13. For "existing", use an ID from existing_events.
14. For "new" or "uncertain", event_id must be null.
15. Return only valid JSON without Markdown fences.

OUTPUT:

{
  "decision": "existing",
  "event_id": null,
  "reason": "Brief factual explanation"
}

For a matching event, replace null with the exact
candidate ID from existing_events.

INPUT:

{$json}
PROMPT;

        $response = $this->gemini->generateText(
            model: (string) config(
                'services.gemini.models.deduplication'
            ),
            prompt: $prompt
        );

        $text = $this->extractModelText($response);

        if ($text === '') {
            throw new RuntimeException(
                'Gemini returned an empty deduplication response.'
            );
        }

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
                'Gemini returned invalid deduplication JSON.',
                previous: $e
            );
        }

        if (
            !is_array($data)
            || !in_array(
                $data['decision'] ?? null,
                ['existing', 'new', 'uncertain'],
                true
            )
            || !is_string($data['reason'] ?? null)
            || trim($data['reason']) === ''
        ) {
            throw new RuntimeException(
                'Gemini returned an invalid deduplication decision.'
            );
        }

        $decision = $data['decision'];
        $eventId = $data['event_id'] ?? null;

        if ($decision === 'existing') {
            $allowedIds = array_column($candidates, 'id');

            if ($batch) {
                if (
                    !is_string($eventId)
                    || !in_array($eventId, $allowedIds, true)
                ) {
                    throw new RuntimeException(
                        'Gemini referenced an unknown batch event ID.'
                    );
                }
            } else {
                if (
                    !is_int($eventId)
                    || !in_array($eventId, $allowedIds, true)
                ) {
                    throw new RuntimeException(
                        'Gemini referenced an unknown existing Event ID.'
                    );
                }
            }
        } elseif ($eventId !== null) {
            throw new RuntimeException(
                'New or uncertain decisions must have null event_id.'
            );
        }

        return [
            'decision' => $decision,
            'event_id' => $eventId,
            'reason' => trim($data['reason']),
            'usage' => $response['usage'] ?? [],
        ];
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
                    && is_string($content['text'] ?? null)
                ) {
                    return trim($content['text']);
                }
            }
        }

        return '';
    }
}
