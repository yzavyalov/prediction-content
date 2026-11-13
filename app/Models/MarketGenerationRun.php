<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MarketGenerationRun extends Model
{
    protected $fillable = [
        'event_id',
        'generation_run_id',
        'status',
        'markets_count',
        'input_tokens',
        'output_tokens',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'event_id' => 'integer',
            'generation_run_id' => 'integer',
            'markets_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    public function generationRuns(): BelongsToMany
    {
        return $this->belongsToMany(
            GenerationRun::class,
            'generation_run_market_generation_run'
        )->withTimestamps();
    }
}
