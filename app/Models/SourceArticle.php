<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SourceArticle extends Model
{
    protected $fillable = [
        'source_id',
        'url',
        'canonical_url',
        'url_hash',
        'title',
        'published_at',
        'summary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function discoveryRuns(): BelongsToMany
    {
        return $this->belongsToMany(
            DiscoveryRun::class,
            'discovery_run_articles'
        )->withPivot('created_at');
    }
}
