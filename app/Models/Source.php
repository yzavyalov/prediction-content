<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = [
        'name',
        'domain',
        'base_url',
        'language_id',
        'status',
        'priority',
        'discovery_frequency_minutes',
        'last_discovery_at',
        'last_successful_discovery_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'discovery_frequency_minutes' => 'integer',
            'last_discovery_at' => 'datetime',
            'last_successful_discovery_at' => 'datetime',
        ];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'source_categories'
        );
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(
            Country::class,
            'source_countries'
        );
    }

    public function countryGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            CountryGroup::class,
            'source_country_groups'
        );
    }

    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class);
    }

    public function sourceArticles(): HasMany
    {
        return $this->hasMany(SourceArticle::class);
    }
}
