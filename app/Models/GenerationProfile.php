<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GenerationProfile extends Model
{
    protected $fillable = [
        'name',
        'status',
        'target_markets_per_run',
        'freshness_minutes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'target_markets_per_run' => 'integer',
            'freshness_minutes' => 'integer',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Category::class,
            'generation_profile_categories'
        );
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(
            Country::class,
            'generation_profile_countries'
        );
    }

    public function countryGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            CountryGroup::class,
            'generation_profile_country_groups'
        );
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(
            Language::class,
            'generation_profile_languages'
        );
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(
            Source::class,
            'generation_profile_sources'
        );
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(GenerationRun::class);
    }

    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class);
    }
}
