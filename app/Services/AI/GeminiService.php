<?php

namespace App\Services\AI;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiService
{
    private string $apiKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.api_key');

        $this->baseUrl = rtrim(
            (string) config(
                'services.gemini.base_url',
                'https://generativelanguage.googleapis.com/v1beta'
            ),
            '/'
        );

        if ($this->apiKey === '') {
            throw new RuntimeException(
                'Gemini API key is not configured.'
            );
        }
    }

    /**
     * Send a request to Gemini.
     *
     * The caller decides which model should be used.
     * GeminiService does not contain business logic.
     */
    public function generate(
        string $model,
        string $prompt,
        bool $googleSearch = false,
    ): array {
        $payload = [
            'model' => $model,
            'input' => $prompt,
        ];

        if ($googleSearch) {
            $payload['tools'] = [
                [
                    'type' => 'google_search',
                ],
            ];
        }

        $response = Http::withHeaders([
            'x-goog-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])
            ->acceptJson()
            ->timeout(120)
            ->post(
                $this->endpoint(),
                $payload
            );

        $this->ensureSuccessful($response);

        return $response->json();
    }

    /**
     * Convenience method for a normal request
     * without Google Search.
     */
    public function generateText(
        string $model,
        string $prompt,
    ): array {
        return $this->generate(
            model: $model,
            prompt: $prompt,
            googleSearch: false,
        );
    }

    /**
     * Convenience method for requests that require
     * fresh information from Google Search.
     */
    public function generateWithSearch(
        string $model,
        string $prompt,
    ): array {
        return $this->generate(
            model: $model,
            prompt: $prompt,
            googleSearch: true,
        );
    }

    private function endpoint(): string
    {
        return $this->baseUrl . '/interactions';
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            sprintf(
                'Gemini API request failed with HTTP %d: %s',
                $response->status(),
                $response->body()
            )
        );
    }
}
