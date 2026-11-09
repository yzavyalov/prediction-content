<?php

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\GenerationProfile;
use App\Models\Language;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    public GenerationProfile $profile;

    public string $name = '';

    public array $category_ids = [];
    public array $country_ids = [];
    public array $country_group_ids = [];
    public array $language_ids = [];

    public string $source_mode = 'all';
    public array $source_ids = [];
    public string $source_search = '';

    public int $freshness_minutes = 1440;
    public int $target_markets_per_run = 10;

    public string $status = 'draft';
    public string $notes = '';

    /*
    |--------------------------------------------------------------------------
    | Load existing profile
    |--------------------------------------------------------------------------
    */

    public function mount(GenerationProfile $profile): void
    {
        $this->profile = $profile->load([
            'categories',
            'countries',
            'countryGroups',
            'languages',
            'sources',
        ]);

        $this->name = $this->profile->name;

        $this->category_ids = $this->profile
            ->categories
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->country_ids = $this->profile
            ->countries
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->country_group_ids = $this->profile
            ->countryGroups
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->language_ids = $this->profile
            ->languages
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->source_ids = $this->profile
            ->sources
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        /*
         * Empty generation_profile_sources means:
         * use all matching active sources.
         */
        $this->source_mode = empty($this->source_ids)
            ? 'all'
            : 'selected';

        $this->freshness_minutes =
            (int) $this->profile->freshness_minutes;

        $this->target_markets_per_run =
            (int) $this->profile->target_markets_per_run;

        $this->status = $this->profile->status;

        $this->notes = $this->profile->notes ?? '';
    }

    /*
    |--------------------------------------------------------------------------
    | Source mode
    |--------------------------------------------------------------------------
    */

    public function updatedSourceMode(): void
    {
        if ($this->source_mode === 'all') {
            $this->source_ids = [];
            $this->source_search = '';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Source search
    |--------------------------------------------------------------------------
    |
    | We intentionally do not load the complete sources table.
    | Search starts after 2 characters and returns at most 20 records.
    |
    */

    public function getSourceResultsProperty()
    {
        $search = trim($this->source_search);

        if (
            $this->source_mode !== 'selected'
            || mb_strlen($search) < 2
        ) {
            return collect();
        }

        return Source::query()
            ->where(function ($query) use ($search) {
                $query
                    ->where(
                        'name',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'domain',
                        'like',
                        '%' . $search . '%'
                    );
            })
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Selected sources
    |--------------------------------------------------------------------------
    */

    public function getSelectedSourcesProperty()
    {
        if (empty($this->source_ids)) {
            return collect();
        }

        return Source::query()
            ->whereIn('id', $this->source_ids)
            ->orderBy('name')
            ->get();
    }

    public function removeSource(int $sourceId): void
    {
        $this->source_ids = array_values(
            array_filter(
                $this->source_ids,
                fn ($id) => (int) $id !== $sourceId
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update profile
    |--------------------------------------------------------------------------
    */

    public function update()
    {
        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'category_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')
                    ->where('is_active', true),
            ],

            'country_ids' => [
                'array',
            ],

            'country_ids.*' => [
                'integer',
                Rule::exists('countries', 'id')
                    ->where('is_active', true),
            ],

            'country_group_ids' => [
                'array',
            ],

            'country_group_ids.*' => [
                'integer',
                Rule::exists('country_groups', 'id')
                    ->where('is_active', true),
            ],

            'language_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'language_ids.*' => [
                'integer',
                Rule::exists('languages', 'id')
                    ->where('is_active', true),
            ],

            'source_mode' => [
                'required',
                Rule::in([
                    'all',
                    'selected',
                ]),
            ],

            'source_ids' => [
                Rule::requiredIf(
                    $this->source_mode === 'selected'
                ),
                'array',
            ],

            'source_ids.*' => [
                'integer',
                Rule::exists('sources', 'id'),
            ],

            'freshness_minutes' => [
                'required',
                'integer',
                Rule::in([
                    60,
                    360,
                    720,
                    1440,
                    2880,
                    4320,
                    10080,
                ]),
            ],

            'target_markets_per_run' => [
                'required',
                'integer',
                'min:1',
                'max:1000',
            ],

            'status' => [
                'required',
                Rule::in([
                    'draft',
                    'active',
                    'disabled',
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:10000',
            ],
        ]);

        /*
         * Geography can be represented by countries,
         * country groups, or both.
         *
         * At least one GEO must be selected.
         */
        if (
            empty($validated['country_ids'])
            && empty($validated['country_group_ids'])
        ) {
            $this->addError(
                'geography',
                'Select at least one country or country group.'
            );

            return;
        }

        DB::transaction(function () use ($validated) {

            /*
             * Main generation profile
             */
            $this->profile->update([
                'name' => trim($validated['name']),

                'status' =>
                    $validated['status'],

                'target_markets_per_run' =>
                    $validated['target_markets_per_run'],

                'freshness_minutes' =>
                    $validated['freshness_minutes'],

                'notes' =>
                    filled($validated['notes'] ?? null)
                        ? trim($validated['notes'])
                        : null,
            ]);

            /*
             * Categories
             */
            $this->profile
                ->categories()
                ->sync(
                    $validated['category_ids']
                );

            /*
             * Countries
             */
            $this->profile
                ->countries()
                ->sync(
                    $validated['country_ids']
                );

            /*
             * Country groups
             */
            $this->profile
                ->countryGroups()
                ->sync(
                    $validated['country_group_ids']
                );

            /*
             * Languages
             */
            $this->profile
                ->languages()
                ->sync(
                    $validated['language_ids']
                );

            /*
             * Sources
             *
             * Empty pivot means:
             * All matching active sources.
             */
            if ($validated['source_mode'] === 'selected') {
                $this->profile
                    ->sources()
                    ->sync(
                        $validated['source_ids']
                    );
            } else {
                $this->profile
                    ->sources()
                    ->sync([]);
            }
        });

        session()->flash(
            'success',
            "Generation profile \"{$this->profile->name}\" updated successfully."
        );

        return $this->redirectRoute(
            'admin.generation-profiles.index',
            navigate: true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Data used by the form
    |--------------------------------------------------------------------------
    */

    public function with(): array
    {
        return [
            'categories' => Category::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),

            'countries' => Country::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),

            'countryGroups' => CountryGroup::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),

            'languages' => Language::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <a
            href="{{ route('admin.generation-profiles.index') }}"
            class="text-sm text-gray-500 hover:text-gray-900"
        >
            ← Back to Generation Profiles
        </a>

        <h1 class="mt-3 text-3xl font-bold">
            Edit Generation Profile
        </h1>

        <p class="mt-2 text-gray-500">
            Update the content discovery and generation configuration.
        </p>
    </div>

    @include('components.admin.generation-profiles._form')
</div>
