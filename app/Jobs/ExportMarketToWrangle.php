<?php

namespace App\Jobs;

use App\Models\Market;
use App\Services\Wrangle\MarketExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExportMarketToWrangle implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(
        public int $marketId
    ) {
        $this->onQueue('wrangle');
    }

    public function uniqueId(): string
    {
        return 'wrangle-market-' . $this->marketId;
    }

    public function handle(MarketExportService $service): void
    {
        $market = Market::query()->find($this->marketId);

        if (! $market) {
            Log::warning('Wrangle export skipped: market not found', [
                'market_id' => $this->marketId,
            ]);

            return;
        }

        $export = $service->send($market);

        if ($export->status === 'completed') {
            Log::info('Wrangle market export completed', [
                'market_id' => $market->id,
                'remote_bet_id' => $export->remote_bet_id,
                'http_status' => $export->http_status,
            ]);

            return;
        }

        if ($export->status === 'failed') {
            Log::warning('Wrangle market export failed', [
                'market_id' => $market->id,
                'attempts' => $export->attempts,
                'http_status' => $export->http_status,
                'error' => $export->error_message,
            ]);

            return;
        }

        Log::info('Wrangle market export skipped', [
            'market_id' => $market->id,
            'status' => $export->status,
        ]);
    }
}
