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

        // No publication date window restriction.
        // Historical articles are allowed.
        // Event date filtering happens during Event Extraction.

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

        // Full article text is optional.
        $content = $article['content'] ?? null;

        if ($content !== null && !is_string($content)) {
            return null;
        }

        $content = is_string($content)
            ? trim($content)
            : null;

        if ($content === '') {
            $content = null;
        }

        return [
            'url' => $url,
            'canonical_url' => $canonicalUrl,
            'url_hash' => $this->canonicalizer->hash($canonicalUrl),
            'title' => $title,
            'published_at' => $publishedAt,
            'summary' => $summary,
            'content' => $content,
        ];
    }

}
