<?php

namespace Tests\Feature;

use App\Jobs\ProcessDiscoveryEvents;
use App\Models\DiscoveryRun;
use App\Models\GenerationRun;
use App\Services\Discovery\DiscoveryExecutor;
use App\Services\Discovery\SourceDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DiscoveryExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    private function createContext(
        bool $withGenerationRun = true,
        int $discoveryCount = 1
    ): array {
        $now = now();

        $profileId = DB::table('generation_profiles')->insertGetId([
            'name' => 'Executor Test Profile',
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

        $generationRun = $withGenerationRun
            ? GenerationRun::create([
                'generation_profile_id' => $profileId,
                'status' => 'running',
                'started_at' => $now,
                'sources_count' => 1,
                'discovery_runs_count' => $discoveryCount,
            ])
            : null;

        $discoveryRuns = [];

        for ($i = 0; $i < $discoveryCount; $i++) {
            $id = DB::table('discovery_runs')->insertGetId([
                'generation_run_id' => $generationRun?->id,
                'generation_profile_id' => $profileId,
                'source_id' => $sourceId,
                'category_id' => $categoryId,
                'geo_type' => 'country',
                'geo_id' => $countryId,
                'window_from' => $now->copy()->subDays(2),
                'window_to' => $now->copy()->subDay(),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $discoveryRuns[] = DiscoveryRun::findOrFail($id);
        }

        return [
            'generation_run' => $generationRun,
            'discovery_runs' => $discoveryRuns,
        ];
    }

    private function mockDiscoverySuccess(
        array $articles = [],
        array $usage = []
    ): void {
        $mock = Mockery::mock(SourceDiscoveryService::class);

        $mock->shouldReceive('discover')
            ->once()
            ->andReturn([
                'articles' => $articles,
                'usage' => $usage,
            ]);

        $this->app->instance(SourceDiscoveryService::class, $mock);
    }

    private function mockDiscoveryFailure(
        string $message = 'Simulated Gemini failure'
    ): void {
        $mock = Mockery::mock(SourceDiscoveryService::class);

        $mock->shouldReceive('discover')
            ->once()
            ->andThrow(new RuntimeException($message));

        $this->app->instance(SourceDiscoveryService::class, $mock);
    }

    public function test_successful_discovery_waits_for_events(): void
    {
        $context = $this->createContext();

        $this->mockDiscoverySuccess([], [
            'google_search_count' => 2,
            'total_input_tokens' => 500,
            'total_output_tokens' => 100,
            'total_tokens' => 600,
        ]);

        $run = app(DiscoveryExecutor::class)->execute(
            $context['discovery_runs'][0]
        );

        $generation = $context['generation_run']->fresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame('pending', $run->events_status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, $run->found_count);
        $this->assertSame(0, $run->accepted_count);
        $this->assertSame(2, $run->google_search_count);
        $this->assertSame(600, $run->total_tokens);

        $this->assertSame('running', $generation->status);
        $this->assertSame(1, $generation->completed_discovery_runs_count);
        $this->assertSame(0, $generation->failed_discovery_runs_count);
        $this->assertSame(2, $generation->google_search_count);
        $this->assertSame(600, $generation->total_tokens);
        $this->assertNull($generation->finished_at);

        Queue::assertPushed(
            ProcessDiscoveryEvents::class,
            fn ($job) => $job->discoveryRunId === $run->id
        );

        Queue::assertPushed(ProcessDiscoveryEvents::class, 1);
    }

    public function test_failed_discovery_marks_generation_failed(): void
    {
        $context = $this->createContext();

        $this->mockDiscoveryFailure();

        try {
            app(DiscoveryExecutor::class)->execute(
                $context['discovery_runs'][0]
            );

            $this->fail('Expected discovery exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Simulated Gemini failure',
                $e->getMessage()
            );
        }

        $run = $context['discovery_runs'][0]->fresh();
        $generation = $context['generation_run']->fresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame(
            'Simulated Gemini failure',
            $run->error_message
        );
        $this->assertNotNull($run->finished_at);

        $this->assertSame('failed', $generation->status);
        $this->assertSame(0, $generation->completed_discovery_runs_count);
        $this->assertSame(1, $generation->failed_discovery_runs_count);
        $this->assertNotNull($generation->finished_at);

        Queue::assertNotPushed(ProcessDiscoveryEvents::class);
    }

    public function test_generation_stays_running_until_all_discoveries_finish(): void
    {
        $context = $this->createContext(
            withGenerationRun: true,
            discoveryCount: 2
        );

        $this->mockDiscoverySuccess();

        $run = app(DiscoveryExecutor::class)->execute(
            $context['discovery_runs'][0]
        );

        $generation = $context['generation_run']->fresh();

        $this->assertSame('running', $generation->status);
        $this->assertSame(1, $generation->completed_discovery_runs_count);
        $this->assertSame(0, $generation->failed_discovery_runs_count);
        $this->assertNull($generation->finished_at);

        $this->assertSame(
            'pending',
            $context['discovery_runs'][1]->fresh()->status
        );

        Queue::assertPushed(
            ProcessDiscoveryEvents::class,
            fn ($job) => $job->discoveryRunId === $run->id
        );

        Queue::assertPushed(ProcessDiscoveryEvents::class, 1);
    }

    public function test_standalone_discovery_completes_without_generation_run(): void
    {
        $context = $this->createContext(
            withGenerationRun: false
        );

        $this->mockDiscoverySuccess();

        $run = app(DiscoveryExecutor::class)->execute(
            $context['discovery_runs'][0]
        );

        $this->assertSame('completed', $run->status);
        $this->assertNull($run->generation_run_id);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, GenerationRun::count());

        Queue::assertPushed(
            ProcessDiscoveryEvents::class,
            fn ($job) => $job->discoveryRunId === $run->id
        );

        Queue::assertPushed(ProcessDiscoveryEvents::class, 1);
    }

    public function test_generation_discovery_window_is_refreshed_at_execution(): void
    {
        $context = $this->createContext();

        $this->mockDiscoverySuccess();

        $run = app(DiscoveryExecutor::class)->execute(
            $context['discovery_runs'][0]
        );

        $this->assertSame(
            $run->started_at->toDateTimeString(),
            $run->window_to->toDateTimeString()
        );

        $this->assertSame(
            1440,
            (int) $run->window_from->diffInMinutes($run->window_to)
        );

        Queue::assertPushed(ProcessDiscoveryEvents::class, 1);
    }

    public function test_standalone_discovery_keeps_original_window(): void
    {
        $context = $this->createContext(
            withGenerationRun: false
        );

        $original = $context['discovery_runs'][0];

        $from = $original->window_from->toDateTimeString();
        $to = $original->window_to->toDateTimeString();

        $this->mockDiscoverySuccess();

        $run = app(DiscoveryExecutor::class)->execute($original);

        $this->assertSame(
            $from,
            $run->window_from->toDateTimeString()
        );

        $this->assertSame(
            $to,
            $run->window_to->toDateTimeString()
        );

        $this->assertSame('completed', $run->status);

        Queue::assertPushed(ProcessDiscoveryEvents::class, 1);
    }
}
