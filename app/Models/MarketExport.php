<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketExport extends Model
{
    protected $fillable = [
        'market_id',
        'destination',
        'status',
        'remote_bet_id',
        'attempts',
        'http_status',
        'error_message',
        'last_attempted_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'market_id' => 'integer',
            'remote_bet_id' => 'integer',
            'attempts' => 'integer',
            'http_status' => 'integer',
            'last_attempted_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [
            'pending',
            'failed',
        ], true);
    }
}
