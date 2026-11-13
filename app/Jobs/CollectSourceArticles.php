<?php

namespace App\Jobs;

use App\Models\Source;
use App\Models\SourceArticle;
use App\Services\Discovery\ArticleUrlCanonicalizer;
use App\Services\Discovery\SourceDiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CollectSourceArticles implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $sourceId,
    ) {
        $this->onQueue('article-collection');
    }

    public function handle(
        SourceDiscoveryService $discovery,
        ArticleUrlCanonicalizer $canonicalizer,
    ): void {
        $source = Source::findOrFail($this->sourceId);

        $found = 0;
        $created = 0;
        $duplicates = 0;

        $result = $discovery->discover(
            source: $source,
            category: $source->categories()->firstOrFail(),
            geo: $source->countries()->first()
            ?? $source->countryGroups()->first()
            ?? throw new RuntimeException(
                'Source has no configured GEO.'
            ),
            from: now()->subYears(20),
            to: now(),
            onArticle: function (array $article) use (
                $source,
                $canonicalizer,
                &$found,
                &$created,
                &$duplicates,
            ): void {
                $found++;

                $url = $canonicalizer->canonicalize(
                    $article['url']
                );

                if ($url === null) {
                    return;
                }

                $record = SourceArticle::firstOrCreate(
                    [
                        'source_id' => $source->id,
                        'url_hash' => hash('sha256', $url),
                    ],
                    [
                        'url' => $article['url'],
                        'canonical_url' => $url,
                        'title' => $article['title'],
                        'published_at' => $article['published_at'],
                        'summary' => $article['summary'] ?? null,
                        'status' => 'discovered',
                    ]
                );

                if ($record->wasRecentlyCreated) {
                    $created++;
                } else {
                    $duplicates++;
                }
            },
        );

        Log::info('Article collection completed', [
            'source_id' => $source->id,
            'found' => $found,
            'created' => $created,
            'duplicates' => $duplicates,
            'remaining_urls' =>
                $result['raw_response']['remaining_urls'] ?? null,
        ]);
    }
}
