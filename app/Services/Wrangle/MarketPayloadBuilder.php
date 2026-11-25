<?php

namespace App\Services\Wrangle;

use App\Models\Market;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MarketPayloadBuilder
{
    public function build(Market $market): array
    {
        $market->load([
            'translations',
            'outcomes.translations',
            'selectedImage',
            'event.category',
            'event.country',
            'event.sourceArticles',
        ]);

        $image = $market->selectedImage;

        if (
            ! $image ||
            $image->status !== 'completed' ||
            ! $image->path
        ) {
            throw new RuntimeException(
                'Market has no completed selected image.'
            );
        }

        $disk = Storage::disk($image->disk);

        if (! $disk->exists($image->path)) {
            throw new RuntimeException(
                'Selected market image file does not exist.'
            );
        }

        $imageBytes = $disk->get($image->path);

        if (! is_string($imageBytes) || $imageBytes === '') {
            throw new RuntimeException(
                'Unable to read selected market image.'
            );
        }

        if (strlen($imageBytes) > 5 * 1024 * 1024) {
            throw new RuntimeException(
                'Selected market image exceeds 5 MB.'
            );
        }

        $imageInfo = @getimagesizefromstring($imageBytes);

        if ($imageInfo === false) {
            throw new RuntimeException(
                'Selected market image is invalid.'
            );
        }

        $mimeType = $imageInfo['mime'] ?? null;

        if (! in_array($mimeType, [
            'image/png',
            'image/jpeg',
            'image/webp',
        ], true)) {
            throw new RuntimeException(
                'Unsupported market image format.'
            );
        }

        $event = $market->event;
        $category = $event?->category;

        if (
            ! $category ||
            ! $category->slug ||
            ! $category->name
        ) {
            throw new RuntimeException(
                'Market event has no valid category.'
            );
        }

        if ($market->outcomes->count() < 2) {
            throw new RuntimeException(
                'Market must have at least two outcomes.'
            );
        }

        $country = $event?->country;

        $sources = $event
            ? $event->sourceArticles
                ->map(function ($article) {
                    return trim(
                        (string) (
                        $article->canonical_url
                            ?: $article->url
                        )
                    );
                })
                ->filter(fn ($url) => filter_var(
                    $url,
                    FILTER_VALIDATE_URL
                ))
                ->unique()
                ->take(3)
                ->values()
                ->all()
            : [];

        return [
            'source_market_id' => $market->id,
            'source_locale' => $market->source_locale,
            'finish_at' => $market->finish_at?->toIso8601String(),

            'question' => $market->question,
            'description' => $market->description,
            'resolution_rules' => $market->resolution_rules,

            'translations' => $market->translations
                ->map(fn ($translation) => [
                    'locale' => $translation->locale,
                    'question' => $translation->question,
                    'description' => $translation->description,
                    'resolution_rules' => $translation->resolution_rules,
                ])
                ->values()
                ->all(),

            'outcomes' => $market->outcomes
                ->map(fn ($outcome) => [
                    'code' => $outcome->code,
                    'title' => $outcome->title,
                    'sort_order' => $outcome->sort_order,
                    'translations' => $outcome->translations
                        ->map(fn ($translation) => [
                            'locale' => $translation->locale,
                            'title' => $translation->title,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),

            'category' => [
                'slug' => $category->slug,
                'name' => $category->name,
            ],

            'country' => $country ? [
                'iso2' => strtoupper($country->iso2),
                'name' => $country->name,
            ] : null,

            'country_groups' => [],

            'sources' => $sources,

            'image' => [
                'mime_type' => $mimeType,
                'data_base64' => base64_encode($imageBytes),
            ],
        ];
    }
}
