<?php

namespace App\Services\Events;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class EventDeduplicationKey
{
    public function make(
        int $categoryId,
        ?int $countryId,
        string $title,
        ?string $eventAt,
        ?string $eventDate = null,
    ): string {
        $normalizedTitle = mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($title)),
            'UTF-8'
        );

        $normalizedDate = null;

        if ($eventAt !== null) {
            if (!preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                $eventAt
            )) {
                throw new InvalidArgumentException(
                    'Event date must be ISO 8601 with a timezone.'
                );
            }

            try {
                $normalizedDate = CarbonImmutable::parse($eventAt)
                    ->utc()
                    ->toIso8601String();
            } catch (\Throwable $e) {
                throw new InvalidArgumentException(
                    'Invalid event date.',
                    previous: $e
                );
            }
        }

        if ($eventDate !== null) {
            if (!preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $eventDate
            )) {
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

        $data = [
            'category_id' => $categoryId,
            'country_id' => $countryId,
            'title' => $normalizedTitle,
            'event_at' => $normalizedDate,
        ];

        // Preserve the original key for legacy calls.
        if ($eventDate !== null) {
            $data['event_date'] = $eventDate;
        }

        return hash(
            'sha256',
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            )
        );
    }
}
