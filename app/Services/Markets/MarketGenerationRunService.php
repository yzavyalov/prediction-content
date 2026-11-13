<?php

namespace App\Services\Markets;

use App\Models\Event;
use App\Models\MarketGenerationRun;
use Illuminate\Support\Facades\DB;

class MarketGenerationRunService
{
    public function create(
        Event $event,
        ?int $generationRunId = null
    ): MarketGenerationRun {
        return DB::transaction(function () use ($event, $generationRunId) {
            Event::query()
                ->lockForUpdate()
                ->findOrFail($event->id);

            $run = MarketGenerationRun::query()
                ->where('event_id', $event->id)
                ->orderByRaw("
                    CASE status
                        WHEN 'completed' THEN 1
                        WHEN 'running' THEN 2
                        WHEN 'pending' THEN 3
                        WHEN 'failed' THEN 4
                        ELSE 5
                    END
                ")
                ->orderByDesc('id')
                ->first();

            if ($run === null) {
                $run = MarketGenerationRun::create([
                    'event_id' => $event->id,
                    'generation_run_id' => $generationRunId,
                    'status' => 'pending',
                    'markets_count' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                ]);
            }

            if ($generationRunId !== null) {
                $run->generationRuns()->syncWithoutDetaching([
                    $generationRunId,
                ]);
            }

            return $run;
        });
    }
}
