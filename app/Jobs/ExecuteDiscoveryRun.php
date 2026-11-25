<?php

namespace App\Jobs;

use App\Models\DiscoveryRun;
use App\Services\Discovery\DiscoveryExecutor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ExecuteDiscoveryRun implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public string $discoveryMode = 'full';

    public bool $extractEvents = true;

    public bool $generateMarkets = true;

    public function __construct(
        public readonly int $discoveryRunId,
        string $discoveryMode = 'full',
        bool $extractEvents = true,
        bool $generateMarkets = true,
    ) {
        $this->discoveryMode = $discoveryMode;
        $this->extractEvents = $extractEvents;
        $this->generateMarkets = $generateMarkets;
    }

    public function uniqueId(): string
    {
        return 'discovery:run:' . $this->discoveryRunId;
    }

    public function handle(DiscoveryExecutor $executor): void
    {
        $lock = Cache::lock(
            'discovery:execution:' . $this->discoveryRunId,
            1860
        );

        if (!$lock->get()) {
            // Другой Worker уже обрабатывает этот Discovery.
            return;
        }

        try {
            $run = DiscoveryRun::findOrFail($this->discoveryRunId);

            if ($run->status !== 'pending') {
                return;
            }

            $executor->execute(
                run: $run,
                discoveryMode: $this->discoveryMode,
                extractEvents: $this->extractEvents,
                generateMarkets: $this->generateMarkets,
            );
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $run = DiscoveryRun::find($this->discoveryRunId);

        if ($run === null) {
            return;
        }

        if (in_array($run->status, ['pending', 'running'], true)) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => mb_substr(
                    $exception?->getMessage() ?? 'Queue job failed unexpectedly.',
                    0,
                    2000
                ),
            ]);
        }

        if ($run->generation_run_id !== null) {
            app(\App\Services\Generation\GenerationRunService::class)
                ->syncProgress($run->generationRun);
        }
    }
}
