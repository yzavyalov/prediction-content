<?php

namespace App\Jobs;

use App\Models\DiscoveryRun;
use App\Services\Discovery\DiscoveryExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteDiscoveryRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $discoveryRunId,
        public readonly int $maxResults = 10,
    ) {
    }

    public function handle(DiscoveryExecutor $executor): void
    {
        $run = DiscoveryRun::findOrFail($this->discoveryRunId);

        if ($run->status !== 'pending') {
            return;
        }

        $executor->execute(
            run: $run,
            maxResults: $this->maxResults,
        );
    }
}
