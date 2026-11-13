<?php

namespace Tests\Feature;


namespace Tests\Feature;

use App\Models\DiscoveryRun;
use App\Services\Events\EventExtractionService;
use App\Services\Events\EventPersistenceService;
use App\Services\Events\EventProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EventProcessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(
        string $status = 'completed',
        string $eventsStatus = 'pending'
    ): DiscoveryRun
    {
        $now = now();

        $profileId = \DB::table('generation_profiles')->insertGetId([
            'name' => 'Event Processing Test',
            'status' => 'active',
            'freshness_minutes' => 1440,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $languageId = \DB::table('languages')->insertGetId([
            'code' => 'ro',
            'name' => 'Romanian',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = \DB::table('categories')->insertGetId([
            'slug' => 'politics',
            'name' => 'Politics',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $countryId = \DB::table('countries')->insertGetId([
            'iso2' => 'RO',
            'name' => 'Romania',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sourceId = \DB::table('sources')->insertGetId([
            'name' => 'Test News',
            'domain' => 'test-news.example',
            'base_url' => 'https://test-news.example',
            'language_id' => $languageId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $runId = \DB::table('discovery_runs')->insertGetId([
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

    private function service(
        EventExtractionService  $extraction,
        EventPersistenceService $persistence
    ): EventProcessingService
    {
        return new EventProcessingService(
            $extraction,
            $persistence
        );
    }

    public function test_successful_event_processing(): void
    {
        $run = $this->makeRun();

        $events = [
            [
                'title' => 'Romanian confidence vote',
                'description' => 'Parliament will vote.',
                'event_at' => '2026-12-10T12:00:00+02:00',
                'article_ids' => [1],
            ],
        ];

        $extraction = Mockery::mock(EventExtractionService::class);
        $extraction->shouldReceive('extract')
            ->once()
            ->andReturn([
                'events' => $events,
                'usage' => [
                    'total_input_tokens' => 120,
                    'total_output_tokens' => 60,
                ],
            ]);

        $persistence = Mockery::mock(EventPersistenceService::class);
        $persistence->shouldReceive('persist')
            ->once()
            ->withArgs(fn($actualRun, $actualEvents) => $actualRun->id === $run->id
                && $actualEvents === $events
            )
            ->andReturn([
                'events' => [],
                'created_count' => 1,
                'existing_count' => 0,
                'attached_articles_count' => 1,
            ]);

        $result = $this->service(
            $extraction,
            $persistence
        )->process($run);

        $this->assertSame('completed', $result->status);
        $this->assertSame('completed', $result->events_status);
        $this->assertSame(1, $result->events_count);
        $this->assertSame(0, $result->events_existing_count);
        $this->assertSame(120, $result->events_input_tokens);
        $this->assertSame(60, $result->events_output_tokens);
        $this->assertNotNull($result->events_started_at);
        $this->assertNotNull($result->events_finished_at);
        $this->assertNull($result->events_error_message);
    }

    public function test_empty_extraction_completes_successfully(): void
    {
        $run = $this->makeRun();

        $extraction = Mockery::mock(EventExtractionService::class);
        $extraction->shouldReceive('extract')
            ->once()
            ->andReturn([
                'events' => [],
                'usage' => [],
            ]);

        $persistence = Mockery::mock(EventPersistenceService::class);
        $persistence->shouldReceive('persist')
            ->once()
            ->andReturn([
                'events' => [],
                'created_count' => 0,
                'existing_count' => 0,
                'attached_articles_count' => 0,
            ]);

        $result = $this->service(
            $extraction,
            $persistence
        )->process($run);

        $this->assertSame('completed', $result->events_status);
        $this->assertSame(0, $result->events_count);
        $this->assertSame(0, $result->events_existing_count);
        $this->assertNotNull($result->events_finished_at);
    }

    public function test_extraction_failure_marks_events_failed(): void
    {
        $run = $this->makeRun();

        $extraction = Mockery::mock(EventExtractionService::class);
        $extraction->shouldReceive('extract')
            ->once()
            ->andThrow(new RuntimeException('Gemini unavailable'));

        $persistence = Mockery::mock(EventPersistenceService::class);
        $persistence->shouldNotReceive('persist');

        try {
            $this->service(
                $extraction,
                $persistence
            )->process($run);

            $this->fail('Expected exception.');
        } catch (RuntimeException $e) {
            $this->assertSame(
                'Gemini unavailable',
                $e->getMessage()
            );
        }

        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame('failed', $run->events_status);
        $this->assertSame(
            'Gemini unavailable',
            $run->events_error_message
        );
        $this->assertNotNull($run->events_finished_at);
    }

    public function test_failed_event_processing_can_be_retried(): void
    {
        $run = $this->makeRun(
            eventsStatus: 'failed'
        );

        $extraction = Mockery::mock(EventExtractionService::class);
        $extraction->shouldReceive('extract')
            ->once()
            ->andReturn([
                'events' => [],
                'usage' => [],
            ]);

        $persistence = Mockery::mock(EventPersistenceService::class);
        $persistence->shouldReceive('persist')
            ->once()
            ->andReturn([
                'events' => [],
                'created_count' => 0,
                'existing_count' => 0,
                'attached_articles_count' => 0,
            ]);

        $result = $this->service(
            $extraction,
            $persistence
        )->process($run);

        $this->assertSame('completed', $result->events_status);
        $this->assertNull($result->events_error_message);
    }

    public function test_incomplete_discovery_cannot_process_events(): void
    {
        $run = $this->makeRun(
            status: 'running'
        );

        $extraction = Mockery::mock(EventExtractionService::class);
        $extraction->shouldNotReceive('extract');

        $persistence = Mockery::mock(EventPersistenceService::class);
        $persistence->shouldNotReceive('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->service(
            $extraction,
            $persistence
        )->process($run);
    }

    public function test_completed_event_processing_cannot_run_again(): void
    {
        $run = $this->makeRun(
            eventsStatus: 'completed'
        );

        $extraction = Mockery::mock(EventExtractionService::class);
        $extraction->shouldNotReceive('extract');

        $persistence = Mockery::mock(EventPersistenceService::class);
        $persistence->shouldNotReceive('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->service(
            $extraction,
            $persistence
        )->process($run);
    }


    public function test_running_event_processing_cannot_start_again(): void
    {
        $run = $this->makeRun(
            eventsStatus: 'running'
        );

        $extraction = Mockery::mock(EventExtractionService::class);
        $extraction->shouldNotReceive('extract');

        $persistence = Mockery::mock(EventPersistenceService::class);
        $persistence->shouldNotReceive('persist');

        try {
            $this->service(
                $extraction,
                $persistence
            )->process($run);

            $this->fail('Expected exception.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                'Discovery Run is not eligible for Event Processing.',
                $e->getMessage()
            );
        }

        $run->refresh();

        $this->assertSame('running', $run->events_status);
        $this->assertNull($run->events_finished_at);
    }

}
