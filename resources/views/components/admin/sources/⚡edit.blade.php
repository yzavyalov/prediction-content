<?php

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\Language;
use App\Models\Source;
use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

new class extends Component
{
    public Source $source;

    public string $name = '';
    public string $domain = '';
    public string $base_url = '';

    public ?int $language_id = null;

    public array $category_ids = [];
    public array $country_ids = [];
    public array $country_group_ids = [];

    public string $status = 'draft';
    public int $priority = 50;
    public int $discovery_frequency_minutes = 120;

    public string $notes = '';

    public function mount(Source $source): void
    {
        $this->source = $source;

        $this->source->load([
            'categories',
            'countries',
            'countryGroups',
        ]);

        $this->name = $source->name;
        $this->domain = $source->domain;
        $this->base_url = $source->base_url;
        $this->language_id = $source->language_id;

        $this->category_ids = $source->categories
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->country_ids = $source->countries
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->country_group_ids = $source->countryGroups
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->status = $source->status;
        $this->priority = $source->priority;
        $this->discovery_frequency_minutes = $source->discovery_frequency_minutes;
        $this->notes = $source->notes ?? '';
    }

    public function save()
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:150'],

            'domain' => [
                'required',
                'string',
                'max:255',
                Rule::unique('sources', 'domain')->ignore($this->source->id),
            ],

            'base_url' => [
                'required',
                'url',
                'max:2048',
            ],

            'language_id' => [
                'required',
                'integer',
                Rule::exists('languages', 'id')->where('is_active', true),
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

            'country_ids' => ['array'],

            'country_ids.*' => [
                'integer',
                Rule::exists('countries', 'id')->where('is_active', true),
            ],

            'country_group_ids' => ['array'],

            'country_group_ids.*' => [
                'integer',
                Rule::exists('country_groups', 'id')->where('is_active', true),
            ],

            'status' => [
                'required',
                Rule::in(['draft', 'testing', 'active', 'disabled']),
            ],

            'priority' => [
                'required',
                'integer',
                'min:0',
                'max:65535',
            ],

            'discovery_frequency_minutes' => [
                'required',
                'integer',
                'min:1',
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ]);

        if (
            empty($validated['country_ids']) &&
            empty($validated['country_group_ids'])
        ) {
            $this->addError(
                'geography',
                'Select at least one country or country group.'
            );

            return;
        }

        DB::transaction(function () use ($validated) {
            $this->source->update([
                'name' => trim($validated['name']),
                'domain' => strtolower(trim($validated['domain'])),
                'base_url' => rtrim($validated['base_url'], '/'),
                'language_id' => $validated['language_id'],
                'status' => $validated['status'],
                'priority' => $validated['priority'],
                'discovery_frequency_minutes' => $validated['discovery_frequency_minutes'],
                'notes' => $validated['notes'] ?: null,
            ]);

            $this->source->categories()->sync($validated['category_ids']);
            $this->source->countries()->sync($validated['country_ids']);
            $this->source->countryGroups()->sync($validated['country_group_ids']);
        });

        return $this->redirectRoute(
            'admin.sources.index',
            navigate: true
        );
    }

    public function with(): array
    {
        return [
            'languages' => Language::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),

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
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <a
            href="{{ route('admin.sources.index') }}"
            class="text-sm text-gray-500 hover:text-gray-900"
        >
            ← Back to Sources
        </a>

        <h1 class="mt-3 text-3xl font-bold">
            Edit Source
        </h1>

        <p class="mt-2 text-gray-500">
            Update {{ $source->name }} source configuration.
        </p>
    </div>

    <div class="max-w-5xl">
        <div class="space-y-8 rounded-xl bg-white p-6 shadow-sm">

            @if ($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                    <div class="font-medium text-red-800">
                        Please correct the following errors:
                    </div>

                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div>
                <h2 class="text-lg font-semibold">Basic information</h2>

                <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium">Name</label>

                        <input
                            type="text"
                            wire:model="name"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">Domain</label>

                        <input
                            type="text"
                            wire:model="domain"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                    </div>

                    <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-medium">Base URL</label>

                        <input
                            type="text"
                            wire:model="base_url"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold">Language</h2>

                <select
                    wire:model="language_id"
                    class="mt-4 w-full rounded-lg border border-gray-300 px-3 py-2.5 md:w-96"
                >
                    <option value="">Select language</option>

                    @foreach ($languages as $language)
                        <option value="{{ $language->id }}">
                            {{ $language->name }} ({{ $language->code }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <h2 class="text-lg font-semibold">Geography</h2>

                <p class="mt-1 text-sm text-gray-500">
                    Geographic coverage is independent from the source language.
                </p>

                <div class="mt-5 grid grid-cols-1 gap-8 lg:grid-cols-2">
                    <div>
                        <h3 class="mb-3 text-sm font-semibold">Country groups</h3>

                        <div class="max-h-72 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-4">
                            @foreach ($countryGroups as $group)
                                <label class="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        value="{{ $group->id }}"
                                        wire:model="country_group_ids"
                                        class="rounded border-gray-300"
                                    >

                                    <span class="text-sm">
                                        {{ $group->name }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <h3 class="mb-3 text-sm font-semibold">Countries</h3>

                        <div class="max-h-72 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-4">
                            @foreach ($countries as $country)
                                <label class="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        value="{{ $country->id }}"
                                        wire:model="country_ids"
                                        class="rounded border-gray-300"
                                    >

                                    <span class="text-sm">
                                        {{ $country->name }}

                                        <span class="text-gray-400">
                                            ({{ $country->iso2 }})
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold">Categories</h2>

                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($categories as $category)
                        <label class="flex items-center gap-3 rounded-lg border border-gray-200 p-3">
                            <input
                                type="checkbox"
                                value="{{ $category->id }}"
                                wire:model="category_ids"
                                class="rounded border-gray-300"
                            >

                            <span class="text-sm">
                                {{ $category->name }}
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold">Discovery settings</h2>

                <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-3">
                    <div>
                        <label class="mb-2 block text-sm font-medium">Status</label>

                        <select
                            wire:model="status"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                            <option value="draft">Draft</option>
                            <option value="testing">Testing</option>
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">Priority</label>

                        <input
                            type="number"
                            wire:model="priority"
                            min="0"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Discovery frequency, minutes
                        </label>

                        <input
                            type="number"
                            wire:model="discovery_frequency_minutes"
                            min="1"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                    </div>
                </div>
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium">Notes</label>

                <textarea
                    wire:model="notes"
                    rows="4"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                ></textarea>
            </div>

            <div class="flex justify-end border-t border-gray-100 pt-6">
                <button
                    type="button"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="save">
                        Save Changes
                    </span>

                    <span wire:loading wire:target="save">
                        Saving...
                    </span>
                </button>
            </div>

        </div>
    </div>
</div>
