<?php

use App\Jobs\PollGeminiMarketGeneration;
use App\Models\MarketGenerationRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    MarketGenerationRun::query()
        ->where('status', 'running')
        ->whereIn('gemini_stage', [
            'url_context',
            'google_search',
        ])
        ->whereNotNull('gemini_interaction_id')
        ->where(function ($query) {
            $query
                ->whereNull('gemini_last_checked_at')
                ->orWhere(
                    'gemini_last_checked_at',
                    '<=',
                    now()->subMinutes(3)
                );
        })
        ->orderBy('id')
        ->chunkById(100, function ($runs) {
            foreach ($runs as $run) {
                PollGeminiMarketGeneration::dispatch($run->id);
            }
        });
})
    ->everyMinute()
    ->name('recover-gemini-market-generations')
    ->withoutOverlapping();
