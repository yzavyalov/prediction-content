<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = [
        'category_id',
        'country_id',
        'title',
        'description',
        'status',
        'event_at',
        'event_date',
        'event_type',
        'source_locale',
        'deduplication_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'event_date' => 'date:Y-m-d',
            'metadata' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function sourceArticles(): BelongsToMany
    {
        return $this->belongsToMany(
            SourceArticle::class,
            'event_source_article'
        )->withTimestamps();
    }

    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(EventTranslation::class);
    }


    public function marketGenerationRuns(): HasMany
    {
        return $this->hasMany(MarketGenerationRun::class);
    }


    public function discoveryRuns(): BelongsToMany
    {
        return $this->belongsToMany(
            DiscoveryRun::class,
            'discovery_run_event'
        )->withTimestamps();
    }


}
