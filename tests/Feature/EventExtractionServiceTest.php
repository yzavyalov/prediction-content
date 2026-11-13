<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Country;
use App\Models\DiscoveryRun;
use App\Models\GenerationProfile;
use App\Models\Source;
use App\Models\SourceArticle;
use App\Services\AI\GeminiService;
use App\Services\Events\EventExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class EventExtractionServiceTest extends TestCase
{
    use RefreshDatabase;


    private function createDiscoveryRun(
        string $status = 'completed'
    ): DiscoveryRun {
        $now = now();

        $profileId = DB::table('generation_profiles')->insertGetId([
            'name' => 'Extraction Test Profile',
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
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DiscoveryRun::findOrFail($runId);
    }


    private function attachArticle(
        DiscoveryRun $run,
        string $title = 'Election scheduled'
    ): SourceArticle {
        $url = 'https://example.com/article-' . uniqid();

        $article = SourceArticle::create([
            'source_id' => $run->source_id,
            'url' => $url,
            'canonical_url' => $url,
            'url_hash' => hash('sha256', $url),
            'title' => $title,
            'summary' => 'An election will take place next month.',
            'published_at' => now(),
            'status' => 'discovered',
        ]);

        $run->sourceArticles()->attach($article->id);

        return $article;
    }

    private function mockGemini(
        array $response
    ): void {
        $mock = Mockery::mock(GeminiService::class);

        $mock->shouldReceive('generateText')
            ->once()
            ->withArgs(function ($model, $prompt) {
                return $model === config(
                        'services.gemini.models.extraction'
                    ) && str_contains($prompt, 'article_id');
            })
            ->andReturn($response);

        $this->app->instance(GeminiService::class, $mock);
    }

    private function geminiResponse(
        string $json
    ): array {
        return [
            'outputs' => [
                [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $json,
                        ],
                    ],
                ],
            ],
            'usage' => [
                'total_input_tokens' => 100,
                'total_output_tokens' => 50,
                'total_tokens' => 150,
            ],
        ];
    }

    public function test_extracts_event_from_article(): void
    {
        $run = $this->createDiscoveryRun();
        $article = $this->attachArticle($run);

        $this->mockGemini(
            $this->geminiResponse(json_encode([
                'events' => [
                    [
                        'title' => 'Upcoming election',
                        'description' => 'An election is scheduled.',
                        'event_at' => null,
                        'article_ids' => [$article->id],
                    ],
                ],
            ]))
        );

        $result = app(EventExtractionService::class)
            ->extract($run);

        $this->assertCount(1, $result['events']);
        $this->assertSame(
            'Upcoming election',
            $result['events'][0]['title']
        );
        $this->assertSame(
            [$article->id],
            $result['events'][0]['article_ids']
        );
        $this->assertSame(1, $result['articles_count']);
        $this->assertSame(
            150,
            $result['usage']['total_tokens']
        );
    }

    public function test_returns_empty_result_without_articles(): void
    {
        $run = $this->createDiscoveryRun();

        $result = app(EventExtractionService::class)
            ->extract($run);

        $this->assertSame([], $result['events']);
        $this->assertSame([], $result['usage']);
        $this->assertSame(0, $result['articles_count']);
    }

    public function test_rejects_incomplete_discovery_run(): void
    {
        $run = $this->createDiscoveryRun('running');

        $this->expectException(\InvalidArgumentException::class);

        app(EventExtractionService::class)->extract($run);
    }

    public function test_rejects_invalid_json(): void
    {
        $run = $this->createDiscoveryRun();
        $this->attachArticle($run);

        $this->mockGemini(
            $this->geminiResponse('not valid json')
        );

        $this->expectException(RuntimeException::class);

        app(EventExtractionService::class)->extract($run);
    }

    public function test_rejects_unknown_article_id(): void
    {
        $run = $this->createDiscoveryRun();
        $this->attachArticle($run);

        $this->mockGemini(
            $this->geminiResponse(json_encode([
                'events' => [
                    [
                        'title' => 'Unknown event',
                        'description' => 'Unsupported event.',
                        'event_at' => null,
                        'article_ids' => [999999],
                    ],
                ],
            ]))
        );

        $this->expectException(RuntimeException::class);

        app(EventExtractionService::class)->extract($run);
    }

    public function test_accepts_empty_events_array(): void
    {
        $run = $this->createDiscoveryRun();
        $this->attachArticle($run);

        $this->mockGemini(
            $this->geminiResponse('{"events":[]}')
        );

        $result = app(EventExtractionService::class)
            ->extract($run);

        $this->assertSame([], $result['events']);
        $this->assertSame(1, $result['articles_count']);
    }
}
