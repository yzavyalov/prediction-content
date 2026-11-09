<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CountryGroup extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(
            Country::class,
            'country_group_country'
        );
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(
            Source::class,
            'source_country_groups'
        );
    }
}
