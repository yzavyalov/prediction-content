<?php

namespace App\Services\Images;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiImageService
{
    private const DEFAULT_MODEL = 'gemini-2.5-flash-image';

    private const API_BASE_URL =
        'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Generate a market image using Gemini.
     *
     * @return array{
     *     data: string,
     *     mime_type: string,
     *     model: string,
     *     prompt: string
     * }
     */
    public function generate(string $marketTitle): array
    {
        $apiKey = config('services.gemini.api_key');

        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new RuntimeException(
                'Gemini API key is not configured.'
            );
        }

        $model = config(
            'services.gemini.models.image',
            self::DEFAULT_MODEL
        );

        $prompt = <<<PROMPT
                    Create a high-quality, visually striking, widescreen editorial
                    illustration for a prediction market in 16:9 landscape format.

                    Market event:
                    {$marketTitle}

                    Requirements:
                    - Illustrate the specific event described in the market.
                    - Make the main subject immediately recognizable.
                    - Use a professional, modern, realistic advertising style.
                    - Include authentic logos, emblems, and recognizable branding
                      of relevant sports teams, companies, or organizations when appropriate.
                    - Make relevant logos prominent and clearly visible.
                    - Do not invent logos or replace them with misleading branding.
                    - Do not present an uncertain future outcome as an established fact.
                    - Use a balanced composition with cinematic lighting and rich details.
                    - Avoid unnecessary text, captions, dates, and watermarks.
                    - Generate exactly one landscape image with a 16:9 aspect ratio.
                    - Use the entire widescreen canvas without borders or empty margins.
                    - Distribute visual elements naturally across the horizontal composition.
                    - Keep important subjects and logos fully visible.
                    - Return an image, not a text explanation.
                    PROMPT;

        $url = self::API_BASE_URL
            . rawurlencode($model)
            . ':generateContent';

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $apiKey,
            ])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(20)
                ->timeout(180)
                ->post($url, [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                [
                                    'text' => $prompt,
                                ],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'responseModalities' => [
                            'IMAGE',
                        ],
                        'imageConfig' => [
                            'aspectRatio' => '16:9',
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            Log::error('Gemini image connection failed', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                'Unable to connect to Gemini image API.',
                0,
                $exception
            );
        }

        if (!$response->successful()) {
            $message = $response->json('error.message');

            $errorDetails = $response->json('error.details', []);

            $diagnostics = [
                'model' => $model,
                'http_status' => $response->status(),
                'error_code' => $response->json('error.code'),
                'error_status' => $response->json('error.status'),
                'error_message' => $message,
                'error_details' => $this->sanitizeDiagnostics(
                    $errorDetails
                ),
            ];

            Log::error(
                'Gemini image API request failed',
                $diagnostics
            );

            throw new RuntimeException(
                'Gemini image generation failed (HTTP '
                . $response->status()
                . '): '
                . (
                is_string($message) && $message !== ''
                    ? $message
                    : 'Unknown API error'
                )
            );
        }

        $candidates = $response->json('candidates', []);

        if (!is_array($candidates)) {
            Log::error(
                'Gemini image response has invalid candidates',
                [
                    'model' => $model,
                    'candidates_type' => get_debug_type($candidates),
                ]
            );

            throw new RuntimeException(
                'Invalid Gemini response: candidates missing.'
            );
        }

        foreach ($candidates as $candidate) {
            $parts = data_get($candidate, 'content.parts', []);

            if (!is_array($parts)) {
                continue;
            }

            foreach ($parts as $part) {
                $inlineData = data_get($part, 'inlineData')
                    ?? data_get($part, 'inline_data');

                if (!is_array($inlineData)) {
                    continue;
                }

                $base64 = $inlineData['data'] ?? null;

                if (!is_string($base64) || $base64 === '') {
                    continue;
                }

                $binary = base64_decode($base64, true);

                if ($binary === false || $binary === '') {
                    throw new RuntimeException(
                        'Gemini returned invalid image data.'
                    );
                }

                $mimeType = $inlineData['mimeType']
                    ?? $inlineData['mime_type']
                    ?? 'image/png';

                if (!in_array($mimeType, [
                    'image/png',
                    'image/jpeg',
                    'image/webp',
                ], true)) {
                    throw new RuntimeException(
                        'Unsupported Gemini image MIME type.'
                    );
                }

                $imageInfo = @getimagesizefromstring($binary);

                if ($imageInfo === false) {
                    throw new RuntimeException(
                        'Gemini returned data that is not a valid image.'
                    );
                }

                $aspectRatio = $imageInfo[0] / $imageInfo[1];

                if (abs($aspectRatio - (16 / 9)) > 0.02) {
                    throw new RuntimeException(
                        'Gemini returned an invalid image aspect ratio: '
                        . $imageInfo[0] . 'x' . $imageInfo[1]
                        . '. Expected 16:9.'
                    );
                }

                Log::info('Gemini market image generated', [
                    'model' => $model,
                    'mime_type' => $mimeType,
                    'width' => $imageInfo[0],
                    'height' => $imageInfo[1],
                    'size_bytes' => strlen($binary),
                    'usage_metadata' => $response->json('usageMetadata', []),
                ]);

                return [
                    'data' => $binary,
                    'mime_type' => $mimeType,
                    'model' => $model,
                    'prompt' => $prompt,
                ];
            }
        }

        /*
         * Gemini returned HTTP 200 but no image.
         * Extract available diagnostics without logging
         * raw image data or the complete API response.
         */

        $candidateDiagnostics = [];

        foreach ($candidates as $index => $candidate) {
            $parts = data_get($candidate, 'content.parts', []);

            $partTypes = [];

            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (data_get($part, 'inlineData') !== null
                        || data_get($part, 'inline_data') !== null) {
                        $partTypes[] = 'inline_image';
                    } elseif (is_string(data_get($part, 'text'))) {
                        $partTypes[] = 'text';
                    } else {
                        $partTypes[] = 'other';
                    }
                }
            }

            $candidateDiagnostics[] = [
                'index' => $index,
                'finish_reason' => data_get(
                    $candidate,
                    'finishReason'
                ),
                'finish_message' => data_get(
                    $candidate,
                    'finishMessage'
                ),
                'safety_ratings' => $this->sanitizeDiagnostics(
                    data_get($candidate, 'safetyRatings', [])
                ),
                'parts_count' => is_array($parts)
                    ? count($parts)
                    : 0,
                'part_types' => $partTypes,
            ];
        }

        $blockReason = $response->json(
            'promptFeedback.blockReason'
        );

        $blockMessage = $response->json(
            'promptFeedback.blockReasonMessage'
        );

        $promptSafetyRatings = $response->json(
            'promptFeedback.safetyRatings',
            []
        );

        $diagnostics = [
            'model' => $model,
            'http_status' => $response->status(),
            'candidate_count' => count($candidates),
            'usage_metadata' => $response->json('usageMetadata', []),
            'candidates' => $candidateDiagnostics,
            'prompt_feedback' => [
                'block_reason' => $blockReason,
                'block_message' => $blockMessage,
                'safety_ratings' => $this->sanitizeDiagnostics(
                    $promptSafetyRatings
                ),
            ],
        ];

        Log::error(
            'Gemini image generation returned no image',
            $diagnostics
        );

        $reasons = [];

        foreach ($candidateDiagnostics as $candidate) {
            $reason = $candidate['finish_reason'];

            if (is_string($reason) && $reason !== '') {
                $reasons[] = $reason;
            }
        }

        $reasons = array_values(array_unique($reasons));

        $messages = [];

        foreach ($candidateDiagnostics as $candidate) {
            $message = $candidate['finish_message'];

            if (is_string($message) && trim($message) !== '') {
                $messages[] = trim($message);
            }
        }

        if (is_string($blockMessage) && trim($blockMessage) !== '') {
            $messages[] = trim($blockMessage);
        }

        $messages = array_values(array_unique($messages));

        $error = 'Gemini returned no image.';

        $error .= ' finishReason=' . (
            $reasons !== []
                ? implode(',', $reasons)
                : 'none'
            );

        $error .= '; blockReason=' . (
            is_string($blockReason) && $blockReason !== ''
                ? $blockReason
                : 'none'
            );

        if ($messages !== []) {
            $error .= '; explanation='
                . mb_substr(implode(' | ', $messages), 0, 1500);
        }

        $error .= '; candidates=' . count($candidates);

        throw new RuntimeException($error);
    }

    /**
     * Keep diagnostic metadata small and avoid
     * accidentally logging raw image payloads.
     */
    private function sanitizeDiagnostics(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                if (in_array(strtolower((string) $key), [
                    'data',
                    'inlinedata',
                    'inline_data',
                    'authorization',
                    'x-goog-api-key',
                    'api_key',
                    'apikey',
                    'token',
                ], true)) {
                    $result[$key] = '[REDACTED]';

                    continue;
                }

                $result[$key] = $this->sanitizeDiagnostics($item);
            }

            return $result;
        }

        if (is_string($value)) {
            return mb_substr($value, 0, 2000);
        }

        return $value;
    }
}
