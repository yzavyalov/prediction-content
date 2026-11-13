<?php

namespace Tests\Feature;

use App\Jobs\ProcessDiscoveryEvents;
use App\Models\DiscoveryRun;
use App\Models\GenerationRun;
use App\Services\Events\EventProcessingService;
use App\Services\Generation\GenerationRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProcessDiscoveryEventsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(
        string $status = 'completed',
        string $eventsStatus = 'pending',
        bool $withGenerationRun = false
    ): DiscoveryRun {
        $now = now();

        $profileId = DB::table('generation_profiles')->insertGetId([
            'name' => 'Process Discovery Events Test',
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

        $generationRunId = null;

        if ($withGenerationRun) {
            $generationRunId = GenerationRun::create([
                'generation_profile_id' => $profileId,
                'status' => 'running',
                'started_at' => $now,
                'sources_count' => 1,
                'discovery_runs_count' => 1,
            ])->id;
        }

        $runId = DB::table('discovery_runs')->insertGetId([
            'generation_run_id' => $generationRunId,
            'generation_profile_id' => $profileId,
            'source_id' => $sourceId,
            'category_id' => $categoryId,
            'geo_type' => 'country',
            'geo_id' => $countryId,
            'window_from' => $now->copy()->subDays(2),
            'window_to' => $now->copy()->subDay(),
            'status' => $status,
            'events_status' => $eventsStatus,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DiscoveryRun::findOrFail($runId);
    }

    private function processor(): EventProcessingService
    {
        return Mockery::mock(EventProcessingService::class);
    }

    private function generationService(): GenerationRunService
    {
        return Mockery::mock(GenerationRunService::class);
    }

    public function test_pending_events_are_processed(): void
    {
        $run = $this->makeRun();

        $processor = $this->processor();

        $processor->shouldReceive('process')
            ->once()
            ->withArgs(fn ($actualRun) =>
                $actualRun instanceof DiscoveryRun
                && $actualRun->id === $run->id
            )
            ->andReturn($run);

        $generationService = $this->generationService();
        $generationService->shouldNotReceive('syncProgress');

        (new ProcessDiscoveryEvents($run->id))->handle(
            $processor,
            $generationService
        );
    }

    public function test_failed_events_can_be_retried(): void
    {
        $run = $this->makeRun(
            eventsStatus: 'failed'
        );

        $processor = $this->processor();
        $processor->shouldReceive('process')
            ->once()
            ->andReturn($run);

        $generationService = $this->generationService();
        $generationService->shouldNotReceive('syncProgress');

        (new ProcessDiscoveryEvents($run->id))->handle(
            $processor,
            $generationService
        );
    }

    public function test_incomplete_discovery_is_skipped(): void
    {
        $run = $this->makeRun(
            status: 'running'
        );

        $processor = $this->processor();
        $processor->shouldNotReceive('process');

        $generationService = $this->generationService();
        $generationService->shouldNotReceive('syncProgress');

        (new ProcessDiscoveryEvents($run->id))->handle(
            $processor,
            $generationService
        );

        $this->assertSame('pending', $run->fresh()->events_status);
    }

    public function test_completed_events_are_skipped(): void
    {
        $run = $this->makeRun(
            eventsStatus: 'completed'
        );

        $processor = $this->processor();
        $processor->shouldNotReceive('process');

        $generationService = $this->generationService();
        $generationService->shouldNotReceive('syncProgress');

        (new ProcessDiscoveryEvents($run->id))->handle(
            $processor,
            $generationService
        );

        $this->assertSame('completed', $run->fresh()->events_status);
    }

    public function test_running_events_are_skipped(): void
    {
        $run = $this->makeRun(
            eventsStatus: 'running'
        );

        $processor = $this->processor();
        $processor->shouldNotReceive('process');

        $generationService = $this->generationService();
        $generationService->shouldNotReceive('syncProgress');

        (new ProcessDiscoveryEvents($run->id))->handle(
            $processor,
            $generationService
        );

        $this->assertSame('running', $run->fresh()->events_status);
    }

    public function test_processing_exception_is_propagated(): void
    {
        $run = $this->makeRun();

        $processor = $this->processor();
        $processor->shouldReceive('process')
            ->once()
            ->andThrow(new RuntimeException('Processing failed'));

        $generationService = $this->generationService();
        $generationService->shouldNotReceive('syncProgress');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Processing failed');

        (new ProcessDiscoveryEvents($run->id))->handle(
            $processor,
            $generationService
        );
    }

    public function test_generation_progress_is_synchronized_after_processing(): void
    {
        $run = $this->makeRun(
            withGenerationRun: true
        );

        $processor = $this->processor();
        $processor->shouldReceive('process')
            ->once()
            ->andReturn($run);

        $generationService = $this->generationService();

        $generationService->shouldReceive('syncProgress')
            ->once()
            ->withArgs(fn ($generationRun) =>
                $generationRun instanceof GenerationRun
                && $generationRun->id === $run->generation_run_id
            )
            ->andReturn($run->generationRun);

        (new ProcessDiscoveryEvents($run->id))->handle(
            $processor,
            $generationService
        );
    }

    public function test_generation_progress_is_synchronized_when_processing_fails(): void
    {
        $run = $this->makeRun(
            withGenerationRun: true
        );

        $processor = $this->processor();

        $processor->shouldReceive('process')
            ->once()
            ->andThrow(new RuntimeException('Processing failed'));

        $generationService = $this->generationService();

        $generationService->shouldReceive('syncProgress')
            ->once()
            ->withArgs(fn ($generationRun) =>
                $generationRun instanceof GenerationRun
                && $generationRun->id === $run->generation_run_id
            )
            ->andReturn($run->generationRun);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Processing failed');

        (new ProcessDiscoveryEvents($run->id))->handle(
            $processor,
            $generationService
        );
    }
}
