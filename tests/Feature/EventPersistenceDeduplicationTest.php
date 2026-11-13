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

class EventPersistenceDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private function createRun(): DiscoveryRun
    {
        $now = now();

        $profileId = DB::table('generation_profiles')->insertGetId([
            'name' => 'Deduplication Integration Test',
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
            'description' => 'A parliamentary confidence vote is scheduled.',
            'event_at' => '2026-12-10T12:00:00+02:00',
            'article_ids' => $articleIds,
        ];
    }

    private function mockDecision(
        string $decision,
        ?int $eventId = null
    ): void {
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
                                    'decision' => $decision,
                                    'event_id' => $eventId,
                                    'reason' => 'Integration test decision.',
                                ]),
                            ],
                        ],
                    ],
                ],
                'usage' => [],
            ]);

        $this->app->instance(GeminiService::class, $gemini);
    }

    public function test_semantic_match_reuses_existing_event(): void
    {
        $run = $this->createRun();

        $firstArticle = $this->attachArticle($run, 'First report');
        $secondArticle = $this->attachArticle($run, 'Second report');

        $service = app(EventPersistenceService::class);

        $service->persist($run, [
            $this->eventData(
                'Romanian parliamentary confidence vote',
                [$firstArticle->id]
            ),
        ]);

        $existing = Event::firstOrFail();

        $this->mockDecision('existing', $existing->id);

        // Resolve again so the service uses the Gemini mock.
        $service = app(EventPersistenceService::class);

        $result = $service->persist($run, [
            $this->eventData(
                'Parliament to vote on Romania new cabinet',
                [$secondArticle->id]
            ),
        ]);

        $this->assertSame(1, Event::count());
        $this->assertSame(0, $result['created_count']);
        $this->assertSame(1, $result['existing_count']);
        $this->assertSame(1, $result['attached_articles_count']);

        $this->assertSame(
            2,
            $existing->fresh()->sourceArticles()->count()
        );
    }

    public function test_new_decision_creates_separate_event(): void
    {
        $run = $this->createRun();

        $firstArticle = $this->attachArticle($run, 'First report');
        $secondArticle = $this->attachArticle($run, 'Second report');

        $service = app(EventPersistenceService::class);

        $service->persist($run, [
            $this->eventData('First parliamentary vote', [
                $firstArticle->id,
            ]),
        ]);

        $this->mockDecision('new');

        $result = app(EventPersistenceService::class)->persist(
            $run,
            [
                $this->eventData('Second parliamentary vote', [
                    $secondArticle->id,
                ]),
            ]
        );

        $this->assertSame(2, Event::count());
        $this->assertSame(1, $result['created_count']);
        $this->assertSame(0, $result['existing_count']);
    }

    public function test_uncertain_decision_does_not_modify_database(): void
    {
        $run = $this->createRun();

        $firstArticle = $this->attachArticle($run, 'First report');
        $secondArticle = $this->attachArticle($run, 'Second report');

        app(EventPersistenceService::class)->persist($run, [
            $this->eventData('Romanian confidence vote', [
                $firstArticle->id,
            ]),
        ]);

        $this->mockDecision('uncertain');

        try {
            app(EventPersistenceService::class)->persist($run, [
                $this->eventData('Romanian cabinet vote', [
                    $secondArticle->id,
                ]),
            ]);

            $this->fail('Expected an uncertain deduplication exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'uncertain',
                strtolower($e->getMessage())
            );
        }

        $this->assertSame(1, Event::count());
        $this->assertSame(
            1,
            DB::table('event_source_article')->count()
        );
    }

    public function test_exact_duplicate_does_not_call_gemini(): void
    {
        $run = $this->createRun();
        $article = $this->attachArticle($run, 'First report');

        $data = $this->eventData(
            'Romanian parliamentary confidence vote',
            [$article->id]
        );

        $service = app(EventPersistenceService::class);

        $service->persist($run, [$data]);

        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldNotReceive('generateText');

        $this->app->instance(GeminiService::class, $gemini);

        $result = app(EventPersistenceService::class)->persist(
            $run,
            [$data]
        );

        $this->assertSame(1, Event::count());
        $this->assertSame(1, $result['existing_count']);
        $this->assertSame(0, $result['attached_articles_count']);
    }
}
