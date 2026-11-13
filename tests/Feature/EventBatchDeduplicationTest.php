<?php

namespace Tests\Feature;

use App\Models\DiscoveryRun;
use App\Models\Event;
use App\Models\SourceArticle;
use App\Services\AI\GeminiService;
use App\Services\Events\EventPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class EventBatchDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private function createRun(): DiscoveryRun
    {
        $now = now();

        $profileId = DB::table('generation_profiles')->insertGetId([
            'name' => 'Batch Deduplication Test',
            'status' => 'active',
            'freshness_minutes' => 1440,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $languageId = DB::table('languages')->insertGetId([
            'code' => 'ro',
            'name' => 'Romanian',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'slug' => 'politics',
            'name' => 'Politics',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'RO',
            'name' => 'Romania',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sourceId = DB::table('sources')->insertGetId([
            'name' => 'Test News',
            'domain' => 'test-news.example',
            'base_url' => 'https://test-news.example',
            'language_id' => $languageId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $runId = DB::table('discovery_runs')->insertGetId([
            'generation_profile_id' => $profileId,
            'source_id' => $sourceId,
            'category_id' => $categoryId,
            'geo_type' => 'country',
            'geo_id' => $countryId,
            'window_from' => $now->copy()->subDays(2),
            'window_to' => $now->copy()->subDay(),
            'status' => 'completed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DiscoveryRun::findOrFail($runId);
    }

    private function attachArticle(
        DiscoveryRun $run,
        string $title
    ): SourceArticle {
        $url = 'https://test-news.example/article-' . uniqid();

        $article = SourceArticle::create([
            'source_id' => $run->source_id,
            'url' => $url,
            'canonical_url' => $url,
            'url_hash' => hash('sha256', $url),
            'title' => $title,
            'summary' => 'Parliament will vote on a cabinet.',
            'published_at' => now(),
            'status' => 'discovered',
        ]);

        $run->sourceArticles()->attach($article->id);

        return $article;
    }

    private function eventData(
        string $title,
        array $articleIds
    ): array {
        return [
            'title' => $title,
            'description' => 'Romanian parliament will vote on a cabinet.',
            'event_at' => '2026-12-10T12:00:00+02:00',
            'article_ids' => $articleIds,
        ];
    }

    public function test_identical_events_in_one_batch_are_merged(): void
    {
        $run = $this->createRun();

        $firstArticle = $this->attachArticle($run, 'First report');
        $secondArticle = $this->attachArticle($run, 'Second report');

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldNotReceive('generateText');

        $this->app->instance(GeminiService::class, $gemini);

        $result = app(EventPersistenceService::class)->persist(
            $run,
            [
                $this->eventData(
                    'Romanian parliamentary confidence vote',
                    [$firstArticle->id]
                ),
                $this->eventData(
                    'Romanian parliamentary confidence vote',
                    [$secondArticle->id]
                ),
            ]
        );

        $this->assertSame(1, Event::count());
        $this->assertSame(1, $result['created_count']);
        $this->assertSame(1, $result['existing_count']);
        $this->assertSame(2, $result['attached_articles_count']);
        $this->assertSame(
            2,
            Event::firstOrFail()->sourceArticles()->count()
        );
    }

    public function test_semantically_identical_events_in_one_batch_are_merged(): void
    {
        $run = $this->createRun();

        $firstArticle = $this->attachArticle($run, 'First report');
        $secondArticle = $this->attachArticle($run, 'Second report');

        $gemini = Mockery::mock(GeminiService::class);

        $gemini->shouldReceive('generateText')
            ->once()
            ->andReturn([
                'outputs' => [
                    [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode([
                                    'decision' => 'existing',
                                    'event_id' => 'batch_0',
                                    'reason' => 'Same parliamentary vote.',
                                ]),
                            ],
                        ],
                    ],
                ],
                'usage' => [],
            ]);

        $this->app->instance(GeminiService::class, $gemini);

        $result = app(EventPersistenceService::class)->persist(
            $run,
            [
                $this->eventData(
                    'Romanian parliamentary confidence vote',
                    [$firstArticle->id]
                ),
                $this->eventData(
                    'Parliament to vote on Romania new cabinet',
                    [$secondArticle->id]
                ),
            ]
        );

        $this->assertSame(1, Event::count());
        $this->assertSame(1, $result['created_count']);
        $this->assertSame(1, $result['existing_count']);
        $this->assertSame(2, $result['attached_articles_count']);
        $this->assertSame(
            2,
            Event::firstOrFail()->sourceArticles()->count()
        );
    }
}
