<?php

namespace App\Services\Discovery;

use App\Models\DiscoveryRun;
use Carbon\CarbonImmutable;
use Throwable;

class DiscoveryArticleValidator
{
    public function __construct(
        private readonly ArticleUrlCanonicalizer $canonicalizer,
    ) {
    }

    public function validate(array $article, DiscoveryRun $run): ?array
    {
        $url = $article['url'] ?? null;
        $title = $article['title'] ?? null;
        $publishedAt = $article['published_at'] ?? null;

        if (
            !is_string($url) ||
            trim($url) === '' ||
            !is_string($title) ||
            trim($title) === '' ||
            !is_string($publishedAt) ||
            trim($publishedAt) === ''
        ) {
            return null;
        }

        $url = trim($url);
        $title = trim($title);

        $canonicalUrl = $this->canonicalizer->canonicalize($url);

        if ($canonicalUrl === null) {
            return null;
        }

        try {
            $publishedAt = CarbonImmutable::parse($publishedAt);
        } catch (Throwable) {
            return null;
        }

        if (
            $publishedAt->lt($run->window_from) ||
            $publishedAt->gt($run->window_to)
        ) {
            return null;
        }

        $summary = $article['summary'] ?? null;

        if ($summary !== null && !is_string($summary)) {
            return null;
        }

        $summary = is_string($summary)
            ? trim($summary)
            : null;

        if ($summary === '') {
            $summary = null;
        }

        return [
            'url' => $url,
            'canonical_url' => $canonicalUrl,
            'url_hash' => $this->canonicalizer->hash($canonicalUrl),
            'title' => $title,
            'published_at' => $publishedAt,
            'summary' => $summary,
        ];
    }
}
