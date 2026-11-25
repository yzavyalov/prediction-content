<?php

namespace App\Jobs;

use App\Models\Market;
use App\Models\MarketImage;
use App\Services\Images\GeminiImageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateMarketImage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 0;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    private const MAX_GENERATION_ATTEMPTS = 3;

    private const NEXT_GENERATION_DELAY = 5;

    private const RETRY_NO_IMAGE_DELAY = 15;

    private const LOCK_SECONDS = 300;

    private const LOCK_KEY = 'gemini:market-images:lock';

    private const NEXT_ALLOWED_KEY = 'gemini:market-images:next-allowed';

    public function __construct(
        public int $marketImageId
    ) {
        $this->onQueue('images');
    }

    public function handle(GeminiImageService $gemini): void
    {
        $image = MarketImage::query()
            ->findOrFail($this->marketImageId);

        if ($image->status === 'completed') {
            return;
        }

        if ($image->attempts >= self::MAX_GENERATION_ATTEMPTS) {
            if ($image->status !== 'failed') {
                $image->update([
                    'status' => 'failed',
                    'is_selected' => false,
                    'error_message' => 'Maximum image generation attempts reached.',
                    'completed_at' => now(),
                ]);
            }

            return;
        }

        /*
         * Only one Gemini image generation may run at a time.
         * Requires a shared cache driver supporting atomic locks.
         */
        $lock = Cache::lock(
            self::LOCK_KEY,
            self::LOCK_SECONDS
        );

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            /*
             * Enforce five seconds between completed requests
             * and the start of the next request.
             */
            $nextAllowed = (int) Cache::get(
                self::NEXT_ALLOWED_KEY,
                0
            );

            $now = time();

            if ($nextAllowed > $now) {
                $this->release($nextAllowed - $now);

                return;
            }

            $this->processImage($image, $gemini);
        } finally {
            $lock->release();
        }
    }

    private function processImage(
        MarketImage $image,
        GeminiImageService $gemini
    ): void {
        $market = Market::query()
            ->with('translations')
            ->findOrFail($image->market_id);

        $image->update([
            'status' => 'processing',
            'attempts' => $image->attempts + 1,
            'started_at' => now(),
            'completed_at' => null,
            'error_message' => null,
        ]);

        $path = null;
        $generationStarted = false;

        try {
            $title = $this->getMarketTitle($market);

            $generationStarted = true;

            try {
                $result = $gemini->generate($title);
            } finally {
                /*
                 * This timestamp is recorded even if Gemini
                 * returns NO_IMAGE or throws an exception.
                 */
                Cache::put(
                    self::NEXT_ALLOWED_KEY,
                    time() + self::NEXT_GENERATION_DELAY,
                    now()->addMinutes(10)
                );
            }

            $extension = match ($result['mime_type']) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => throw new RuntimeException(
                    'Unsupported generated image format.'
                ),
            };

            $path = 'market-images/'
                . $market->id
                . '/'
                . $image->id
                . '.'
                . $extension;

            $saved = Storage::disk($image->disk)
                ->put($path, $result['data']);

            if (! $saved) {
                throw new RuntimeException(
                    'Unable to save generated market image.'
                );
            }

            $dimensions = getimagesizefromstring(
                $result['data']
            );

            if ($dimensions === false) {
                throw new RuntimeException(
                    'Unable to determine image dimensions.'
                );
            }

            DB::transaction(function () use (
                $image,
                $result,
                $path,
                $dimensions
            ): void {
                MarketImage::query()
                    ->where('market_id', $image->market_id)
                    ->where('is_selected', true)
                    ->update([
                        'is_selected' => false,
                    ]);

                $image->update([
                    'provider' => 'gemini',
                    'model' => $result['model'],
                    'prompt' => $result['prompt'],
                    'path' => $path,
                    'mime_type' => $result['mime_type'],
                    'width' => $dimensions[0],
                    'height' => $dimensions[1],
                    'size_bytes' => strlen($result['data']),
                    'status' => 'completed',
                    'is_selected' => true,
                    'completed_at' => now(),
                    'error_message' => null,
                ]);
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk($image->disk)->delete($path);
            }

            $isNoImage = str_contains(
                $exception->getMessage(),
                'finishReason=NO_IMAGE'
            );

            if (
                $isNoImage
                && $image->attempts < self::MAX_GENERATION_ATTEMPTS
            ) {
                $image->update([
                    'status' => 'pending',
                    'is_selected' => false,
                    'error_message' => mb_substr(
                        $exception->getMessage(),
                        0,
                        5000
                    ),
                ]);

                $baseDelay = self::RETRY_NO_IMAGE_DELAY
                    * (3 ** ($image->attempts - 1));

                $jitter = random_int(-25, 25) / 100;

                $retryDelay = max(
                    1,
                    (int) round($baseDelay * (1 + $jitter))
                );

                $this->release($retryDelay);

                return;
            }

            $image->update([
                'status' => 'failed',
                'is_selected' => false,
                'error_message' => mb_substr(
                    $exception->getMessage(),
                    0,
                    5000
                ),
                'completed_at' => now(),
            ]);

            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        MarketImage::query()
            ->whereKey($this->marketImageId)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status' => 'failed',
                'is_selected' => false,
                'error_message' => mb_substr(
                    $exception?->getMessage()
                    ?? 'Image generation job failed.',
                    0,
                    5000
                ),
                'completed_at' => now(),
            ]);
    }

    private function getMarketTitle(Market $market): string
    {
        $englishTranslation = $market->translations
            ->first(function ($translation): bool {
                $locale = strtolower(
                    str_replace(
                        '_',
                        '-',
                        trim((string) $translation->locale)
                    )
                );

                return $locale === 'en'
                    || str_starts_with($locale, 'en-');
            });

        $title = trim(
            (string) (
            $englishTranslation?->question
                ?: $market->question
            )
        );

        if ($title === '') {
            throw new RuntimeException(
                'Market title is empty.'
            );
        }

        return $title;
    }
}
