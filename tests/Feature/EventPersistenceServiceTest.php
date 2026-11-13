<?php

namespace Tests\Feature;

use App\Models\DiscoveryRun;
use App\Models\Event;
use App\Models\SourceArticle;
use App\Services\Events\EventPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class EventPersistenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createDiscoveryRun(
        string $status = 'completed'
    ): DiscoveryRun {
        $now = now();

        $profileId = DB::table('generation_profiles')->insertGetId([
            'name' => 'Persistence Test Profile',
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
        string $title = 'Parliament schedules a vote'
    ): SourceArticle {
        $url = 'https://test-news.example/article-' . uniqid();

        $article = SourceArticle::create([
            'source_id' => $run->source_id,
            'url' => $url,
            'canonical_url' => $url,
            'url_hash' => hash('sha256', $url),
            'title' => $title,
            'summary' => 'Parliament will vote on a new government.',
            'published_at' => now(),
            'status' => 'discovered',
        ]);

        $run->sourceArticles()->attach($article->id);

        return $article;
    }

    private function eventData(array $articleIds): array
    {
        return [
            'title' => 'Romanian parliamentary confidence vote',
            'description' => 'Parliament will vote on the proposed cabinet.',
            'event_at' => '2026-12-10T12:00:00+02:00',
            'article_ids' => $articleIds,
        ];
    }

    public function test_creates_event_and_attaches_article(): void
    {
        $run = $this->createDiscoveryRun();
        $article = $this->attachArticle($run);

        $result = app(EventPersistenceService::class)->persist(
            $run,
            [$this->eventData([$article->id])]
        );

        $this->assertSame(1, $result['created_count']);
        $this->assertSame(0, $result['existing_count']);
        $this->assertSame(1, $result['attached_articles_count']);
        $this->assertCount(1, $result['events']);
        $this->assertSame(1, Event::count());

        $event = Event::firstOrFail();

        $this->assertSame('draft', $event->status);
        $this->assertSame('ro', $event->source_locale);
        $this->assertSame($run->category_id, $event->category_id);
        $this->assertSame((int) $run->geo_id, $event->country_id);
        $this->assertSame(64, strlen($event->deduplication_key));

        $this->assertTrue(
            $event->sourceArticles()
                ->whereKey($article->id)
                ->exists()
        );
    }

    public function test_repeated_save_does_not_duplicate_event_or_article(): void
    {
        $run = $this->createDiscoveryRun();
        $article = $this->attachArticle($run);
        $data = $this->eventData([$article->id]);

        $service = app(EventPersistenceService::class);

        $service->persist($run, [$data]);
        $result = $service->persist($run, [$data]);

        $this->assertSame(0, $result['created_count']);
        $this->assertSame(1, $result['existing_count']);
        $this->assertSame(0, $result['attached_articles_count']);
        $this->assertSame(1, Event::count());
        $this->assertSame(1, DB::table('event_source_article')->count());
    }

    public function test_adds_new_article_to_existing_event(): void
    {
        $run = $this->createDiscoveryRun();

        $firstArticle = $this->attachArticle($run, 'First report');
        $secondArticle = $this->attachArticle($run, 'Second report');

        $service = app(EventPersistenceService::class);

        $service->persist(
            $run,
            [$this->eventData([$firstArticle->id])]
        );

        $result = $service->persist(
            $run,
            [$this->eventData([$secondArticle->id])]
        );

        $this->assertSame(0, $result['created_count']);
        $this->assertSame(1, $result['existing_count']);
        $this->assertSame(1, $result['attached_articles_count']);
        $this->assertSame(1, Event::count());
        $this->assertSame(2, DB::table('event_source_article')->count());
    }

    public function test_rejects_article_outside_discovery_run(): void
    {
        $run = $this->createDiscoveryRun();
        $this->attachArticle($run);

        $this->expectException(RuntimeException::class);

        app(EventPersistenceService::class)->persist(
            $run,
            [$this->eventData([999999])]
        );
    }

    public function test_rejects_incomplete_discovery_run(): void
    {
        $run = $this->createDiscoveryRun('running');

        $this->expectException(InvalidArgumentException::class);

        app(EventPersistenceService::class)->persist($run, []);
    }

    public function test_rolls_back_all_events_when_one_is_invalid(): void
    {
        $run = $this->createDiscoveryRun();
        $article = $this->attachArticle($run);

        $valid = $this->eventData([$article->id]);
        $invalid = $this->eventData([999999]);

        try {
            app(EventPersistenceService::class)->persist(
                $run,
                [$valid, $invalid]
            );

            $this->fail('Expected persistence exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'outside this Discovery Run',
                $e->getMessage()
            );
        }

        $this->assertSame(0, Event::count());
        $this->assertSame(
            0,
            DB::table('event_source_article')->count()
        );
    }

}
