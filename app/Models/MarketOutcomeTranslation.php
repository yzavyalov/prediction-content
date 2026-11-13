<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketOutcomeTranslation extends Model
{
    protected $fillable = [
        'market_outcome_id',
        'locale',
        'title',
    ];

    public function marketOutcome(): BelongsTo
    {
        return $this->belongsTo(MarketOutcome::class);
    }
}
