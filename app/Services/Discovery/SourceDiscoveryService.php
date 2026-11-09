<?php

namespace App\Services\Discovery;

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\Source;
use App\Services\AI\GeminiService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SourceDiscoveryService
{
    public function __construct(
        private readonly GeminiService $gemini,
    ) {
    }

    /**
     * Discover recent articles for one controlled Discovery unit:
     *
     * Source × Category × GEO × Time Window
     */
    public function discover(
        Source $source,
        Category $category,
        Country|CountryGroup $geo,
        CarbonInterface $from,
        CarbonInterface $to,
        int $maxResults = 10,
    ): array {
        $maxResults = max(1, min($maxResults, 20));

        $prompt = $this->buildPrompt(
            source: $source,
            category: $category,
            geo: $geo,
            from: $from,
            to: $to,
            maxResults: $maxResults,
        );

        $response = $this->gemini->generateWithSearch(
            config('services.gemini.models.discovery'),
            $prompt,
        );

        $articles = $this->extractArticles($response);

        $extractedCount = count($articles);

        $articles = $this->normalizeArticleUrls(
            articles: $articles,
            source: $source,
        );

        \Log::info('Discovery article extraction', [
            'source_id' => $source->id,
            'source_domain' => $source->domain,
            'extracted_count' => $extractedCount,
            'normalized_count' => count($articles),
            'model_output' => $this->extractModelText($response),
        ]);

        // Count actual Google Search calls reported by
        // the Gemini Interactions API.
        $googleSearchCount = count(array_filter(
            $response['steps'] ?? [],
            fn ($step) => is_array($step)
                && ($step['type'] ?? null) === 'google_search_call'
        ));

        return [
            'articles' => $articles,
            'usage' => array_merge(
                $response['usage'] ?? [],
                [
                    'google_search_count' => $googleSearchCount,
                ]
            ),
            'raw_response' => $response,
        ];
    }

    private function buildPrompt(
        Source $source,
        Category $category,
        Country|CountryGroup $geo,
        CarbonInterface $from,
        CarbonInterface $to,
        int $maxResults,
    ): string {
        $language = $source->language?->name ?? 'Unknown';

        $geoName = $geo->name;

        $geoCode = $geo instanceof Country
            ? $geo->iso2
            : $geo->slug;

        $fromIso = $from->toIso8601String();
        $toIso = $to->toIso8601String();

        return <<<PROMPT
You are performing low-cost article discovery for a prediction-market content platform.

Your ONLY task is to discover recent source articles.
Do not create events.
Do not evaluate marketability.
Do not create prediction markets.

SOURCE:
Name: {$source->name}
Domain: {$source->domain}
Language: {$language}

CATEGORY:
{$category->name}

GEOGRAPHY:
{$geoName} ({$geoCode})

TIME WINDOW:
From: {$fromIso}
To: {$toIso}

GOAL:
Find up to {$maxResults} relevant articles from the specified source.

COST CONTROL:
- Minimize Google Search usage.
- Use one broad Google Search query if possible.
- Do not search separately for each article.
- Do not verify individual articles with additional searches.
- Do not search separately for individual people, events, titles, or dates.
- Prefer collecting multiple relevant article URLs from the same search results.
- Do not spend additional searches trying to reach {$maxResults} articles.
- Returning fewer valid articles is better than performing additional searches.
- If the first search produces usable results, use those results and finish.

OUTPUT:
Return ONLY valid JSON.
No Markdown.
No explanations.

{
  "articles": [
    {
      "url": "https://example.com/article",
      "title": "Article title",
      "published_at": "2026-10-23T10:00:00+03:00",
      "summary": "Short factual summary."
    }
  ]
}

RULES:
- Every URL must belong to {$source->domain}.
- Do not invent URLs.
- Do not invent titles.
- Do not invent publication dates.
- Only include articles published inside the specified TIME WINDOW.
- Articles must be relevant to the specified CATEGORY and GEOGRAPHY.
- Use the publication time shown by the source when available.
- Keep summaries short and factual.
- Do not include duplicate URLs.
- If fewer than {$maxResults} suitable articles are available, return fewer.
- If none are found, return {"articles":[]}.
PROMPT;
    }

    private function extractArticles(array $response): array
    {
        $text = $this->extractModelText($response);

        if ($text === null || trim($text) === '') {
            throw new RuntimeException(
                'Gemini Discovery response does not contain model output.'
            );
        }

        $text = trim($text);

        // Defensive cleanup in case the model unexpectedly wraps JSON
        // inside a Markdown code fence.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $text = trim($text);
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Gemini Discovery returned invalid JSON: ' . json_last_error_msg()
            );
        }

        if (
            !array_key_exists('articles', $decoded) ||
            !is_array($decoded['articles'])
        ) {
            throw new RuntimeException(
                'Gemini Discovery response does not contain an articles array.'
            );
        }

        return $decoded['articles'];
    }

    private function normalizeArticleUrls(array $articles, Source $source): array
    {
        $normalized = [];

        foreach ($articles as $article) {
            if (!is_array($article)) {
                continue;
            }

            $url = $article['url'] ?? null;

            if (!is_string($url) || trim($url) === '') {
                continue;
            }

            $url = trim($url);

            if ($this->isGroundingRedirect($url)) {
                $url = $this->resolveGroundingRedirect($url);
            }

            if ($url === null || !$this->belongsToSource($url, $source)) {
                continue;
            }

            $article['url'] = $url;

            $normalized[] = $article;
        }

        return $normalized;
    }

    private function isGroundingRedirect(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host)
            && strtolower($host) === 'vertexaisearch.cloud.google.com';
    }

    private function resolveGroundingRedirect(string $url): ?string
    {
        try {
            $response = Http::withoutRedirecting()
                ->timeout(15)
                ->get($url);

            if (!$response->redirect()) {
                return null;
            }

            $location = $response->header('Location');

            if (!is_string($location) || trim($location) === '') {
                return null;
            }

            return trim($location);
        } catch (\Throwable) {
            return null;
        }
    }

    private function belongsToSource(string $url, Source $source): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $sourceDomain = strtolower(trim($source->domain));

        if ($scheme !== 'https' || $host === '' || $sourceDomain === '') {
            return false;
        }

        return $host === $sourceDomain
            || str_ends_with($host, '.' . $sourceDomain);
    }

    private function extractModelText(array $response): ?string
    {
        foreach (array_reverse($response['steps'] ?? []) as $step) {
            if (($step['type'] ?? null) !== 'model_output') {
                continue;
            }

            foreach ($step['content'] ?? [] as $content) {
                if (
                    ($content['type'] ?? null) === 'text' &&
                    isset($content['text']) &&
                    is_string($content['text'])
                ) {
                    return $content['text'];
                }
            }
        }

        return null;
    }
}
