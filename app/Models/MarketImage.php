<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MarketImage extends Model
{
    protected $fillable = [
        'market_id',
        'provider',
        'model',
        'prompt',
        'disk',
        'path',
        'mime_type',
        'width',
        'height',
        'size_bytes',
        'status',
        'attempts',
        'error_message',
        'is_selected',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'attempts' => 'integer',
            'is_selected' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function getUrlAttribute(): ?string
    {
        if (!$this->path) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    public function getIsReadyAttribute(): bool
    {
        return $this->status === 'completed'
            && filled($this->path);
    }

    public function getIsProcessingAttribute(): bool
    {
        return in_array(
            $this->status,
            ['pending', 'processing'],
            true
        );
    }

    public function scopeReady($query)
    {
        return $query
            ->where('status', 'completed')
            ->whereNotNull('path');
    }

    public function scopeSelected($query)
    {
        return $query->where('is_selected', true);
    }
}
