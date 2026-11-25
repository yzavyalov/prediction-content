<?php

namespace App\Services\Events;

use App\Models\DiscoveryRun;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class EventProcessingService
{
    public function __construct(
        private readonly EventExtractionService $extraction,
        private readonly EventPersistenceService $persistence,
    ) {
    }

    public function process(DiscoveryRun $run): DiscoveryRun
    {
        if ($run->status !== 'completed') {
            throw new InvalidArgumentException(
                'Events can only be processed from a completed Discovery Run.'
            );
        }

        $updated = DiscoveryRun::query()
            ->whereKey($run->id)
            ->where('status', 'completed')
            ->whereIn('events_status', ['pending', 'failed'])
            ->update([
                'events_status' => 'running',
                'events_started_at' => now(),
                'events_finished_at' => null,
                'events_error_message' => null,
            ]);

        if ($updated !== 1) {
            throw new RuntimeException(
                'Discovery Run is not available for event processing.'
            );
        }

        $run->refresh();

        $startedAt = microtime(true);

        $extractionUsage = [];
        $deduplicationUsage = [];

        try {
            Log::info(
                'Event processing started',
                [
                    'discovery_run_id' => $run->id,
                    'source_id' => $run->source_id,
                ]
            );

            /*
             * Phase 1: extract events from saved articles.
             */
            $extracted = $this->extraction->extract($run);

            $events = $extracted['events'] ?? [];

            $extractionUsage = $extracted['usage'] ?? [];

            Log::info(
                'Event processing extraction completed',
                [
                    'discovery_run_id' => $run->id,
                    'articles_count' =>
                        $extracted['articles_count'] ?? 0,
                    'extracted_events_count' => count($events),
                    'usage' => $extractionUsage,
                ]
            );

            /*
             * Phase 2: deduplicate and persist events.
             */
            $persisted = $this->persistence->persist(
                $run,
                $events
            );

            $deduplicationUsage = $persisted['usage'] ?? [];

            /*
             * Phase 3: calculate combined token usage.
             */
            $totalUsage = $this->combineUsage(
                $extractionUsage,
                $deduplicationUsage
            );

            /*
             * Phase 4: save final processing statistics.
             */
            $run->update([
                'events_status' => 'completed',
                'events_count' => $persisted['created_count'],
                'events_existing_count' =>
                    $persisted['existing_count'],
                'events_finished_at' => now(),
                'events_error_message' => null,
                'events_input_tokens' =>
                    $totalUsage['total_input_tokens'],
                'events_output_tokens' =>
                    $totalUsage['total_output_tokens'],
            ]);

            $elapsedSeconds = round(
                microtime(true) - $startedAt,
                2
            );

            Log::info(
                'Event processing completed',
                [
                    'discovery_run_id' => $run->id,
                    'articles_count' =>
                        $extracted['articles_count'] ?? 0,
                    'extracted_events_count' =>
                        count($events),
                    'unique_events_count' =>
                        count($persisted['events']),
                    'created_events_count' =>
                        $persisted['created_count'],
                    'existing_events_count' =>
                        $persisted['existing_count'],
                    'attached_articles_count' =>
                        $persisted['attached_articles_count'],
                    'deduplication_requests_count' =>
                        $persisted['deduplication_requests_count']
                        ?? 0,
                    'extraction_usage' =>
                        $extractionUsage,
                    'deduplication_usage' =>
                        $deduplicationUsage,
                    'total_usage' => $totalUsage,
                    'elapsed_seconds' => $elapsedSeconds,
                ]
            );

            $run->refresh();

            $run->setRelation(
                'processedEvents',
                collect($persisted['events'])
                    ->unique('id')
                    ->values()
            );

            return $run;

        } catch (Throwable $e) {
            $totalUsage = $this->combineUsage(
                $extractionUsage,
                $deduplicationUsage
            );

            $run->update([
                'events_status' => 'failed',
                'events_finished_at' => now(),
                'events_error_message' => $e->getMessage(),
                'events_input_tokens' =>
                    $totalUsage['total_input_tokens'],
                'events_output_tokens' =>
                    $totalUsage['total_output_tokens'],
            ]);

            Log::error(
                'Event processing failed',
                [
                    'discovery_run_id' => $run->id,
                    'error' => $e->getMessage(),
                    'extraction_usage' => $extractionUsage,
                    'deduplication_usage' => $deduplicationUsage,
                    'known_total_usage' => $totalUsage,
                    'elapsed_seconds' => round(
                        microtime(true) - $startedAt,
                        2
                    ),
                ]
            );

            throw $e;
        }
    }

    private function combineUsage(
        array ...$usageItems
    ): array {
        $total = [
            'total_input_tokens' => 0,
            'total_output_tokens' => 0,
            'total_tokens' => 0,
        ];

        foreach ($usageItems as $usage) {
            $input = (int) (
                $usage['total_input_tokens'] ?? 0
            );

            $output = (int) (
                $usage['total_output_tokens'] ?? 0
            );

            $tokens = (int) (
                $usage['total_tokens']
                ?? ($input + $output)
            );

            $total['total_input_tokens'] += $input;
            $total['total_output_tokens'] += $output;
            $total['total_tokens'] += $tokens;
        }

        return $total;
    }
}
