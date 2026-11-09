<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Country extends Model
{
    protected $fillable = [
        'iso2',
        'iso3',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(
            Source::class,
            'source_countries'
        );
    }

    public function countryGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            CountryGroup::class,
            'country_group_country'
        );
    }
}
