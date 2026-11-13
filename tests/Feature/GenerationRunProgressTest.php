<?php

namespace Tests\Feature;

use App\Models\GenerationRun;
use App\Services\Generation\GenerationRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenerationRunProgressTest extends TestCase
{
    use RefreshDatabase;

    private function createGenerationRun(
        string $status = 'running'
    ): array {
        $now = now();

        $profileId = DB::table('generation_profiles')->insertGetId([
            'name' => 'Test Generation Profile',
            'status' => 'active',
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

        $generationRun = GenerationRun::create([
            'generation_profile_id' => $profileId,
            'status' => $status,
            'started_at' => $status === 'pending'
                ? null
                : $now->copy()->subMinutes(5),
            'sources_count' => 1,
            'discovery_runs_count' => 2,
        ]);

        return [
            'run' => $generationRun,
            'profile_id' => $profileId,
            'source_id' => $sourceId,
            'category_id' => $categoryId,
            'country_id' => $countryId,
        ];
    }

    private function createDiscoveryRun(
        array $context,
        string $status,
        array $stats = []
    ): void {
        $now = now();

        DB::table('discovery_runs')->insert([
            'generation_run_id' => $context['run']->id,
            'generation_profile_id' => $context['profile_id'],
            'source_id' => $context['source_id'],
            'category_id' => $context['category_id'],
            'geo_type' => 'country',
            'geo_id' => $context['country_id'],
            'window_from' => $now->copy()->subDay(),
            'window_to' => $now,
            'status' => $status,
            'events_status' => $stats['events_status'] ?? 'pending',
            'started_at' => $status === 'pending'
                ? null
                : $now->copy()->subMinutes(4),
            'finished_at' => in_array(
                $status,
                ['completed', 'failed'],
                true
            ) ? $now : null,
            'found_count' => $stats['found_count'] ?? 0,
            'accepted_count' => $stats['accepted_count'] ?? 0,
            'rejected_count' => $stats['rejected_count'] ?? 0,
            'google_search_count' => $stats['google_search_count'] ?? 0,
            'input_tokens' => $stats['input_tokens'] ?? 0,
            'output_tokens' => $stats['output_tokens'] ?? 0,
            'total_tokens' => $stats['total_tokens'] ?? 0,
            'events_count' => $stats['events_count'] ?? 0,
            'events_existing_count' =>
                $stats['events_existing_count'] ?? 0,
            'events_input_tokens' =>
                $stats['events_input_tokens'] ?? 0,
            'events_output_tokens' =>
                $stats['events_output_tokens'] ?? 0,
            'error_message' => $status === 'failed'
                ? 'Test discovery error'
                : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_it_synchronizes_completed_runs_without_double_counting(): void
    {
        $context = $this->createGenerationRun();

        $this->createDiscoveryRun($context, 'completed', [
            'accepted_count' => 3,
            'google_search_count' => 1,
            'input_tokens' => 500,
            'output_tokens' => 100,
            'total_tokens' => 600,
            'events_status' => 'completed',
            'events_count' => 2,
            'events_input_tokens' => 200,
            'events_output_tokens' => 50,
        ]);

        $this->createDiscoveryRun($context, 'completed', [
            'accepted_count' => 2,
            'google_search_count' => 2,
            'input_tokens' => 700,
            'output_tokens' => 150,
            'total_tokens' => 850,
            'events_status' => 'completed',
            'events_count' => 1,
            'events_input_tokens' => 100,
            'events_output_tokens' => 30,
        ]);

        $service = app(GenerationRunService::class);

        $service->syncProgress($context['run']);
        $run = $service->syncProgress($context['run']);

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->discovery_runs_count);
        $this->assertSame(2, $run->completed_discovery_runs_count);
        $this->assertSame(0, $run->failed_discovery_runs_count);
        $this->assertSame(5, $run->articles_count);
        $this->assertSame(3, $run->events_count);
        $this->assertSame(3, $run->google_search_count);
        $this->assertSame(1500, $run->input_tokens);
        $this->assertSame(330, $run->output_tokens);
        $this->assertSame(1830, $run->total_tokens);
        $this->assertNotNull($run->finished_at);
    }

    public function test_it_waits_for_event_processing_after_discovery(): void
    {
        $context = $this->createGenerationRun();

        $this->createDiscoveryRun($context, 'completed', [
            'accepted_count' => 3,
            'events_status' => 'completed',
            'events_count' => 2,
        ]);

        $this->createDiscoveryRun($context, 'completed', [
            'accepted_count' => 2,
            'events_status' => 'pending',
        ]);

        $run = app(GenerationRunService::class)
            ->syncProgress($context['run']);

        $this->assertSame('running', $run->status);
        $this->assertSame(2, $run->completed_discovery_runs_count);
        $this->assertSame(2, $run->events_count);
        $this->assertNull($run->finished_at);
    }


    public function test_it_marks_generation_failed_when_a_discovery_run_fails(): void
    {
        $context = $this->createGenerationRun();

        $this->createDiscoveryRun($context, 'completed', [
            'accepted_count' => 3,
            'google_search_count' => 1,
            'input_tokens' => 500,
            'output_tokens' => 100,
            'total_tokens' => 600,
            'events_status' => 'pending',
        ]);

        $this->createDiscoveryRun($context, 'failed');

        $service = app(GenerationRunService::class);

        // Discovery завершён, но Events ещё обрабатываются.
        $run = $service->syncProgress($context['run']);

        $this->assertSame('running', $run->status);
        $this->assertSame(2, $run->discovery_runs_count);
        $this->assertSame(1, $run->completed_discovery_runs_count);
        $this->assertSame(1, $run->failed_discovery_runs_count);
        $this->assertSame(3, $run->articles_count);
        $this->assertSame(1, $run->google_search_count);
        $this->assertSame(600, $run->total_tokens);
        $this->assertNull($run->finished_at);

        // Имитируем успешное завершение обработки Events.
        DB::table('discovery_runs')
            ->where('generation_run_id', $context['run']->id)
            ->where('status', 'completed')
            ->update([
                'events_status' => 'completed',
                'events_count' => 2,
            ]);

        // Теперь весь процесс должен завершиться с ошибкой Discovery.
        $run = $service->syncProgress($context['run']);

        $this->assertSame('failed', $run->status);
        $this->assertSame(2, $run->events_count);
        $this->assertSame(1, $run->failed_discovery_runs_count);
        $this->assertNotNull($run->finished_at);
    }


    public function test_it_marks_generation_failed_when_event_processing_fails(): void
    {
        $context = $this->createGenerationRun();

        $this->createDiscoveryRun($context, 'completed', [
            'events_status' => 'completed',
            'events_count' => 2,
        ]);

        $this->createDiscoveryRun($context, 'completed', [
            'events_status' => 'failed',
        ]);

        $run = app(GenerationRunService::class)
            ->syncProgress($context['run']);

        $this->assertSame('failed', $run->status);
        $this->assertSame(2, $run->completed_discovery_runs_count);
        $this->assertSame(2, $run->events_count);
        $this->assertNotNull($run->finished_at);
    }

    public function test_it_keeps_generation_running_while_discovery_is_in_progress(): void
    {
        $context = $this->createGenerationRun();

        $this->createDiscoveryRun($context, 'completed', [
            'accepted_count' => 3,
            'google_search_count' => 1,
            'input_tokens' => 500,
            'output_tokens' => 100,
            'total_tokens' => 600,
        ]);

        $this->createDiscoveryRun($context, 'running');

        $run = app(GenerationRunService::class)
            ->syncProgress($context['run']);

        $this->assertSame('running', $run->status);
        $this->assertSame(2, $run->discovery_runs_count);
        $this->assertSame(1, $run->completed_discovery_runs_count);
        $this->assertSame(0, $run->failed_discovery_runs_count);
        $this->assertSame(3, $run->articles_count);
        $this->assertSame(1, $run->google_search_count);
        $this->assertSame(600, $run->total_tokens);
        $this->assertNull($run->finished_at);
    }

    public function test_it_preserves_pending_status_before_execution(): void
    {
        $context = $this->createGenerationRun('pending');

        $this->createDiscoveryRun($context, 'pending');
        $this->createDiscoveryRun($context, 'pending');

        $run = app(GenerationRunService::class)
            ->syncProgress($context['run']);

        $this->assertSame('pending', $run->status);
        $this->assertSame(2, $run->discovery_runs_count);
        $this->assertSame(0, $run->completed_discovery_runs_count);
        $this->assertSame(0, $run->failed_discovery_runs_count);
        $this->assertSame(0, $run->articles_count);
        $this->assertSame(0, $run->events_count);
        $this->assertSame(0, $run->google_search_count);
        $this->assertNull($run->started_at);
        $this->assertNull($run->finished_at);
    }

    public function test_it_waits_for_all_discovery_runs_before_marking_generation_failed(): void
    {
        $context = $this->createGenerationRun();

        $this->createDiscoveryRun($context, 'failed');
        $this->createDiscoveryRun($context, 'running');

        $run = app(GenerationRunService::class)
            ->syncProgress($context['run']);

        $this->assertSame('running', $run->status);
        $this->assertSame(2, $run->discovery_runs_count);
        $this->assertSame(0, $run->completed_discovery_runs_count);
        $this->assertSame(1, $run->failed_discovery_runs_count);
        $this->assertNull($run->finished_at);
    }

    public function test_it_preserves_finished_at_when_synchronizing_completed_generation_again(): void
    {
        $context = $this->createGenerationRun();

        $this->createDiscoveryRun($context, 'completed', [
            'accepted_count' => 2,
            'total_tokens' => 300,
            'events_status' => 'completed',
        ]);

        $this->createDiscoveryRun($context, 'completed', [
            'accepted_count' => 1,
            'total_tokens' => 200,
            'events_status' => 'completed',
        ]);

        $service = app(GenerationRunService::class);

        $first = $service->syncProgress($context['run']);

        $this->assertNotNull($first->finished_at);

        $finishedAt = $first->finished_at->toDateTimeString();

        $second = $service->syncProgress($context['run']);

        $this->assertSame('completed', $second->status);
        $this->assertSame(
            $finishedAt,
            $second->finished_at->toDateTimeString()
        );
        $this->assertSame(3, $second->articles_count);
        $this->assertSame(500, $second->total_tokens);
        $this->assertSame(2, $second->completed_discovery_runs_count);
    }
}
