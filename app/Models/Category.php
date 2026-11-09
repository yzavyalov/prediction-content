<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(
            Source::class,
            'source_categories'
        );
    }

    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class);
    }
}
