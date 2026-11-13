<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;

class DiscoveryRun extends Model
{
    protected $fillable = [
        'generation_run_id',
        'generation_profile_id',
        'source_id',
        'category_id',
        'geo_type',
        'geo_id',
        'window_from',
        'window_to',
        'status',
        'started_at',
        'finished_at',
        'found_count',
        'accepted_count',
        'rejected_count',
        'google_search_count',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'error_message',

        // Event Processing
        'events_status',
        'events_count',
        'events_existing_count',
        'events_started_at',
        'events_finished_at',
        'events_error_message',
        'events_input_tokens',
        'events_output_tokens',
        'markets_dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'generation_run_id' => 'integer',
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'found_count' => 'integer',
            'accepted_count' => 'integer',
            'rejected_count' => 'integer',
            'google_search_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',

            // Event Processing
            'events_count' => 'integer',
            'events_existing_count' => 'integer',
            'events_started_at' => 'datetime',
            'events_finished_at' => 'datetime',
            'markets_dispatched_at' => 'datetime',
            'events_input_tokens' => 'integer',
            'events_output_tokens' => 'integer',
        ];
    }

    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(GenerationRun::class);
    }

    public function generationProfile(): BelongsTo
    {
        return $this->belongsTo(GenerationProfile::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function resolveGeo(): Country|CountryGroup
    {
        return match ($this->geo_type) {
            'country' => Country::findOrFail($this->geo_id),
            'country_group' => CountryGroup::findOrFail($this->geo_id),
            default => throw new InvalidArgumentException(
                "Unsupported Discovery Run geo_type: {$this->geo_type}"
            ),
        };
    }

    public function sourceArticles(): BelongsToMany
    {
        return $this->belongsToMany(
            SourceArticle::class,
            'discovery_run_articles'
        )->withPivot('created_at');
    }


    public function events(): BelongsToMany
    {
        return $this->belongsToMany(
            Event::class,
            'discovery_run_event'
        )->withTimestamps();
    }

}
