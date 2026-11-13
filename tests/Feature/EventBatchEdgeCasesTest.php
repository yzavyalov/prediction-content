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
use RuntimeException;
use Tests\TestCase;

class EventBatchEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function createRun(): DiscoveryRun
    {
        $now = now();

        $profileId = DB::table('generation_profiles')->insertGetId([
            'name' => 'Batch Edge Cases Test',
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
            'summary' => 'Romanian parliament will vote on a cabinet.',
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

    private function mockGeminiDecisions(array $decisions): void
    {
        $gemini = Mockery::mock(GeminiService::class);

        $expectation = $gemini
            ->shouldReceive('generateText')
            ->times(count($decisions));

        foreach ($decisions as $decision) {
            $expectation->andReturn(
                [
                    'outputs' => [
                        [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => json_encode(
                                        $decision,
                                        JSON_THROW_ON_ERROR
                                    ),
                                ],
                            ],
                        ],
                    ],
                    'usage' => [],
                ]
            );
        }

        $this->app->instance(GeminiService::class, $gemini);
    }

    public function test_multiple_headlines_reuse_one_database_event(): void
    {
        $run = $this->createRun();

        $firstArticle = $this->attachArticle($run, 'First report');
        $secondArticle = $this->attachArticle($run, 'Second report');
        $thirdArticle = $this->attachArticle($run, 'Third report');

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldNotReceive('generateText');
        $this->app->instance(GeminiService::class, $gemini);

        $firstResult = app(EventPersistenceService::class)->persist(
            $run,
            [
                $this->eventData(
                    'Romanian parliamentary confidence vote',
                    [$firstArticle->id]
                ),
            ]
        );

        $existingEvent = $firstResult['events'][0];

        $this->mockGeminiDecisions([
            [
                'decision' => 'existing',
                'event_id' => $existingEvent->id,
                'reason' => 'Same parliamentary vote.',
            ],
            [
                'decision' => 'existing',
                'event_id' => $existingEvent->id,
                'reason' => 'Same parliamentary vote.',
            ],
        ]);

        $result = app(EventPersistenceService::class)->persist(
            $run,
            [
                $this->eventData(
                    'Parliament prepares confidence vote',
                    [$secondArticle->id]
                ),
                $this->eventData(
                    'Romanian cabinet faces parliamentary vote',
                    [$thirdArticle->id]
                ),
            ]
        );

        $this->assertSame(1, Event::count());
        $this->assertSame(0, $result['created_count']);
        $this->assertSame(2, $result['existing_count']);
        $this->assertSame(2, $result['attached_articles_count']);

        $this->assertSame(
            3,
            $existingEvent->sourceArticles()->count()
        );

        $this->assertSame(
            1,
            collect($result['events'])->pluck('id')->unique()->count(),
            'The result must reference only one unique database Event.'
        );
    }

    public function test_repeated_article_is_attached_only_once(): void
    {
        $run = $this->createRun();

        $article = $this->attachArticle($run, 'Repeated report');

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldNotReceive('generateText');
        $this->app->instance(GeminiService::class, $gemini);

        $result = app(EventPersistenceService::class)->persist(
            $run,
            [
                $this->eventData(
                    'Romanian parliamentary confidence vote',
                    [$article->id]
                ),
                $this->eventData(
                    'Romanian parliamentary confidence vote',
                    [$article->id]
                ),
            ]
        );

        $this->assertSame(1, Event::count());
        $this->assertSame(1, $result['created_count']);
        $this->assertSame(1, $result['existing_count']);
        $this->assertSame(1, $result['attached_articles_count']);

        $this->assertSame(
            1,
            Event::firstOrFail()->sourceArticles()->count()
        );

        $this->assertDatabaseCount('event_source_article', 1);
    }

    public function test_uncertain_batch_decision_prevents_all_writes(): void
    {
        $run = $this->createRun();

        $firstArticle = $this->attachArticle($run, 'First report');
        $secondArticle = $this->attachArticle($run, 'Second report');

        $this->mockGeminiDecisions([
            [
                'decision' => 'uncertain',
                'event_id' => null,
                'reason' => 'Not enough information to identify the event.',
            ],
        ]);

        try {
            app(EventPersistenceService::class)->persist(
                $run,
                [
                    $this->eventData(
                        'Romanian parliamentary confidence vote',
                        [$firstArticle->id]
                    ),
                    $this->eventData(
                        'Romanian cabinet confidence ballot',
                        [$secondArticle->id]
                    ),
                ]
            );

            $this->fail(
                'Expected uncertain batch deduplication to stop persistence.'
            );
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Batch event deduplication is uncertain',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, Event::count());
        $this->assertDatabaseCount('event_source_article', 0);
    }
}
