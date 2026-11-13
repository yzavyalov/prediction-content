<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class GenerationRun extends Model
{
    protected $fillable = [
        'generation_profile_id',
        'status',
        'started_at',
        'finished_at',
        'sources_count',
        'discovery_runs_count',
        'completed_discovery_runs_count',
        'failed_discovery_runs_count',
        'articles_count',
        'events_count',
        'markets_count',
        'google_search_count',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',

            'sources_count' => 'integer',
            'discovery_runs_count' => 'integer',
            'completed_discovery_runs_count' => 'integer',
            'failed_discovery_runs_count' => 'integer',

            'articles_count' => 'integer',
            'events_count' => 'integer',
            'markets_count' => 'integer',

            'google_search_count' => 'integer',

            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
        ];
    }

    public function generationProfile(): BelongsTo
    {
        return $this->belongsTo(GenerationProfile::class);
    }

    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class);
    }

    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }


    public function marketGenerationRuns(): HasMany
    {
        return $this->hasMany(MarketGenerationRun::class);
    }

    public function linkedMarketGenerationRuns(): BelongsToMany
    {
        return $this->belongsToMany(
            MarketGenerationRun::class,
            'generation_run_market_generation_run'
        )->withTimestamps();
    }
}
