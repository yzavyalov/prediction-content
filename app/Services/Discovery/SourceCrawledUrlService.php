<?php

namespace App\Services\Discovery;

use Illuminate\Support\Facades\DB;

class SourceCrawledUrlService
{
    public function __construct(
        private readonly ArticleUrlCanonicalizer $canonicalizer,
    ) {
    }

    /**
     * Check whether a URL has already been processed
     * successfully for this source.
     */
    public function isProcessed(int $sourceId, string $url): bool
    {
        $hash = $this->getUrlHash($url);

        if ($hash === null) {
            return false;
        }

        return DB::table('source_crawled_urls')
            ->where('source_id', $sourceId)
            ->where('url_hash', $hash)
            ->exists();
    }

    /**
     * Save a successfully processed URL.
     *
     * Repeated calls do not create duplicates.
     */
    public function markProcessed(int $sourceId, string $url): void
    {
        $hash = $this->getUrlHash($url);

        if ($hash === null) {
            return;
        }

        DB::table('source_crawled_urls')->insertOrIgnore([
            'source_id' => $sourceId,
            'url_hash' => $hash,
            'processed_at' => now(),
        ]);
    }

    /**
     * Save multiple successfully processed URLs.
     *
     * Useful for batch processing.
     */
    public function markProcessedBatch(int $sourceId, array $urls): void
    {
        $rows = [];
        $processedAt = now();

        foreach ($urls as $url) {
            if (!is_string($url)) {
                continue;
            }

            $hash = $this->getUrlHash($url);

            if ($hash === null) {
                continue;
            }

            $rows[$hash] = [
                'source_id' => $sourceId,
                'url_hash' => $hash,
                'processed_at' => $processedAt,
            ];
        }

        if ($rows === []) {
            return;
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('source_crawled_urls')->insertOrIgnore($chunk);
        }
    }

    /**
     * Calculate the hash using the same canonicalization
     * algorithm as source_articles.
     */
    private function getUrlHash(string $url): ?string
    {
        $canonicalUrl = $this->canonicalizer->canonicalize($url);

        if ($canonicalUrl === null) {
            return null;
        }

        return $this->canonicalizer->hash($canonicalUrl);
    }
}
