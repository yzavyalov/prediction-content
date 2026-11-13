<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    protected $fillable = [
        'event_id',
        'generation_run_id',
        'question',
        'description',
        'status',
        'source_locale',
        'finish_at',
        'resolution_at',
        'resolved_at',
        'resolution_rules',
        'winning_outcome_id',
        'deduplication_key',
        'metadata',
        'monitoring_enabled',
        'monitoring_status',
        'last_checked_at',
        'next_check_at',
        'monitoring_attempts',
    ];

    protected function casts(): array
    {
        return [
            'finish_at' => 'datetime',
            'resolution_at' => 'datetime',
            'resolved_at' => 'datetime',
            'metadata' => 'array',
            'monitoring_enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'monitoring_attempts' => 'integer',
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

    public function outcomes(): HasMany
    {
        return $this->hasMany(MarketOutcome::class)
            ->orderBy('sort_order');
    }

    public function winningOutcome(): BelongsTo
    {
        return $this->belongsTo(
            MarketOutcome::class,
            'winning_outcome_id'
        );
    }

    public function translations(): HasMany
    {
        return $this->hasMany(MarketTranslation::class);
    }
}
