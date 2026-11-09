<?php

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\Language;
use App\Models\Source;
use Livewire\Component;
use App\Models\GenerationProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

new class extends Component
{
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

    public function updatedSourceMode(): void
    {
        if ($this->source_mode === 'all') {
            $this->source_ids = [];
            $this->source_search = '';
        }
    }

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
                    ->where('name', 'like', '%' . $search . '%')
                    ->orWhere('domain', 'like', '%' . $search . '%');
            })
            ->orderBy('name')
            ->limit(20)
            ->get();
    }

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

    public function save()
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
                Rule::exists('categories', 'id')->where('is_active', true),
            ],

            'country_ids' => [
                'array',
            ],
            'country_ids.*' => [
                'integer',
                Rule::exists('countries', 'id')->where('is_active', true),
            ],

            'country_group_ids' => [
                'array',
            ],
            'country_group_ids.*' => [
                'integer',
                Rule::exists('country_groups', 'id')->where('is_active', true),
            ],

            'language_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'language_ids.*' => [
                'integer',
                Rule::exists('languages', 'id')->where('is_active', true),
            ],

            'source_mode' => [
                'required',
                Rule::in(['all', 'selected']),
            ],

            'source_ids' => [
                Rule::requiredIf($this->source_mode === 'selected'),
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

        $profile = DB::transaction(function () use ($validated) {
            $profile = GenerationProfile::create([
                'name' => trim($validated['name']),
                'status' => $validated['status'],
                'target_markets_per_run' => $validated['target_markets_per_run'],
                'freshness_minutes' => $validated['freshness_minutes'],
                'notes' => filled($validated['notes'] ?? null)
                    ? trim($validated['notes'])
                    : null,
            ]);

            $profile->categories()->sync(
                $validated['category_ids']
            );

            $profile->countries()->sync(
                $validated['country_ids']
            );

            $profile->countryGroups()->sync(
                $validated['country_group_ids']
            );

            $profile->languages()->sync(
                $validated['language_ids']
            );

            if ($validated['source_mode'] === 'selected') {
                $profile->sources()->sync(
                    $validated['source_ids']
                );
            } else {
                $profile->sources()->sync([]);
            }

            return $profile;
        });

        session()->flash(
            'success',
            "Generation profile \"{$profile->name}\" created successfully."
        );

        return $this->redirectRoute(
            'admin.generation-profiles.index',
            navigate: true
        );
    }

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
            Add Generation Profile
        </h1>

        <p class="mt-2 text-gray-500">
            Define what content the platform should discover and generate.
        </p>
    </div>

    @include('components.admin.generation-profiles._form')
</div>
