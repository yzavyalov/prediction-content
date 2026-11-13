<?php

namespace App\Services\Markets;

use App\Models\Event;
use InvalidArgumentException;

class MarketValidationService
{
    public function validate(Event $event, array $markets): array
    {
        if (!array_is_list($markets)) {
            throw new InvalidArgumentException(
                'Markets must be a list.'
            );
        }

        $requiredLocales = array_values(array_unique([
            strtolower($event->source_locale),
            'en',
        ]));

        foreach ($markets as $index => $market) {
            if (!is_array($market)) {
                throw new InvalidArgumentException(
                    "Market {$index} must be an object."
                );
            }

            foreach (['question', 'description', 'resolution_rules'] as $field) {
                if (
                    !is_string($market[$field] ?? null)
                    || trim($market[$field]) === ''
                ) {
                    throw new InvalidArgumentException(
                        "Market {$index}: invalid {$field}."
                    );
                }
            }

            if (mb_strlen($market['question']) > 500) {
                throw new InvalidArgumentException(
                    "Market {$index}: question exceeds 500 characters."
                );
            }

            foreach (['finish_at', 'resolution_at'] as $field) {
                if (
                    !array_key_exists($field, $market)
                    || (
                        $market[$field] !== null
                        && (
                            !is_string($market[$field])
                            || !preg_match(
                                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                                $market[$field]
                            )
                        )
                    )
                ) {
                    throw new InvalidArgumentException(
                        "Market {$index}: invalid {$field}."
                    );
                }
            }

            if ($market['finish_at'] === null) {
                throw new InvalidArgumentException(
                    "Market {$index}: finish_at is required."
                );
            }

            try {
                $finishAt = \Carbon\CarbonImmutable::parse(
                    $market['finish_at']
                );
            } catch (\Throwable $e) {
                throw new InvalidArgumentException(
                    "Market {$index}: invalid finish_at.",
                    previous: $e
                );
            }

            if ($finishAt->lessThanOrEqualTo(now('UTC'))) {
                throw new InvalidArgumentException(
                    "Market {$index}: finish_at must be in the future."
                );
            }

            if ($market['resolution_at'] !== null) {
                $resolutionAt = \Carbon\CarbonImmutable::parse(
                    $market['resolution_at']
                );

                if ($resolutionAt->lessThan($finishAt)) {
                    throw new InvalidArgumentException(
                        "Market {$index}: resolution_at cannot precede finish_at."
                    );
                }
            }

            if (
                !isset($market['outcomes'])
                || !is_array($market['outcomes'])
                || !array_is_list($market['outcomes'])
                || count($market['outcomes']) < 2
            ) {
                throw new InvalidArgumentException(
                    "Market {$index}: at least two outcomes required."
                );
            }

            $codes = [];

            foreach ($market['outcomes'] as $outcome) {
                if (
                    !is_array($outcome)
                    || !is_string($outcome['code'] ?? null)
                    || !preg_match('/^[a-z0-9_]{1,100}$/', $outcome['code'])
                    || !is_string($outcome['title'] ?? null)
                    || trim($outcome['title']) === ''
                    || mb_strlen($outcome['title']) > 255
                    || isset($codes[$outcome['code']])
                ) {
                    throw new InvalidArgumentException(
                        "Market {$index}: invalid or duplicate outcome."
                    );
                }

                $codes[$outcome['code']] = true;
            }

            if (
                !isset($market['translations'])
                || !is_array($market['translations'])
                || !array_is_list($market['translations'])
            ) {
                throw new InvalidArgumentException(
                    "Market {$index}: translations must be a list."
                );
            }

            $locales = [];

            foreach ($market['translations'] as $translation) {
                if (
                    !is_array($translation)
                    || !is_string($translation['locale'] ?? null)
                ) {
                    throw new InvalidArgumentException(
                        "Market {$index}: invalid translation locale."
                    );
                }

                $locale = strtolower($translation['locale']);

                if (
                    !preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $locale)
                    || isset($locales[$locale])
                ) {
                    throw new InvalidArgumentException(
                        "Market {$index}: invalid or duplicate locale."
                    );
                }

                $locales[$locale] = true;

                foreach (['question', 'description', 'resolution_rules'] as $field) {
                    if (
                        !is_string($translation[$field] ?? null)
                        || trim($translation[$field]) === ''
                    ) {
                        throw new InvalidArgumentException(
                            "Market {$index}: invalid translated {$field}."
                        );
                    }
                }

                if (mb_strlen($translation['question']) > 500) {
                    throw new InvalidArgumentException(
                        "Market {$index}: translated question too long."
                    );
                }

                if (
                    !isset($translation['outcomes'])
                    || !is_array($translation['outcomes'])
                    || !array_is_list($translation['outcomes'])
                    || count($translation['outcomes']) !== count($codes)
                ) {
                    throw new InvalidArgumentException(
                        "Market {$index}: translated outcomes mismatch."
                    );
                }

                $translatedCodes = [];

                foreach ($translation['outcomes'] as $outcome) {
                    if (
                        !is_array($outcome)
                        || !is_string($outcome['code'] ?? null)
                        || !isset($codes[$outcome['code']])
                        || isset($translatedCodes[$outcome['code']])
                        || !is_string($outcome['title'] ?? null)
                        || trim($outcome['title']) === ''
                        || mb_strlen($outcome['title']) > 255
                    ) {
                        throw new InvalidArgumentException(
                            "Market {$index}: invalid translated outcome."
                        );
                    }

                    $translatedCodes[$outcome['code']] = true;
                }
            }

            foreach ($requiredLocales as $locale) {
                if (!isset($locales[$locale])) {
                    throw new InvalidArgumentException(
                        "Market {$index}: missing required locale {$locale}."
                    );
                }
            }
        }

        return $markets;
    }
}
