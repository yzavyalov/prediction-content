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

        @error('geography')
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {{ $message }}
        </div>
        @enderror

        {{-- Basic information --}}
        <div>
            <h2 class="text-lg font-semibold">
                Basic information
            </h2>

            <div class="mt-4">
                <label class="mb-2 block text-sm font-medium">
                    Profile name
                </label>

                <input
                    type="text"
                    wire:model="name"
                    placeholder="Romania Politics"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2.5 md:w-1/2"
                >
            </div>
        </div>

        {{-- Categories --}}
        <div>
            <h2 class="text-lg font-semibold">
                Categories
            </h2>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($categories as $category)
                    <label
                        wire:key="category-{{ $category->id }}"
                        class="flex items-center gap-3 rounded-lg border border-gray-200 p-3"
                    >
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

        {{-- Geography --}}
        <div>
            <h2 class="text-lg font-semibold">
                Geography
            </h2>

            <div class="mt-5 grid grid-cols-1 gap-8 lg:grid-cols-2">

                {{-- Country groups --}}
                <div>
                    <h3 class="mb-3 text-sm font-semibold">
                        Country groups
                    </h3>

                    <div class="max-h-72 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-4">
                        @foreach ($countryGroups as $group)
                            <label
                                wire:key="country-group-{{ $group->id }}"
                                class="flex items-center gap-3"
                            >
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

                {{-- Countries --}}
                <div>
                    <h3 class="mb-3 text-sm font-semibold">
                        Countries
                    </h3>

                    <div class="max-h-72 space-y-2 overflow-y-auto rounded-lg border border-gray-200 p-4">
                        @foreach ($countries as $country)
                            <label
                                wire:key="country-{{ $country->id }}"
                                class="flex items-center gap-3"
                            >
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

        {{-- Languages --}}
        <div>
            <h2 class="text-lg font-semibold">
                Languages
            </h2>

            <p class="mt-1 text-sm text-gray-500">
                Languages are independent from geography.
            </p>

            <div class="mt-4 grid max-h-72 grid-cols-1 gap-2 overflow-y-auto rounded-lg border border-gray-200 p-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($languages as $language)
                    <label
                        wire:key="language-{{ $language->id }}"
                        class="flex items-center gap-3"
                    >
                        <input
                            type="checkbox"
                            value="{{ $language->id }}"
                            wire:model="language_ids"
                            class="rounded border-gray-300"
                        >

                        <span class="text-sm">
                                {{ $language->name }}

                                <span class="text-gray-400">
                                    ({{ $language->code }})
                                </span>
                            </span>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Sources --}}
        <div>
            <h2 class="text-lg font-semibold">
                Sources
            </h2>

            <p class="mt-1 text-sm text-gray-500">
                Choose whether Discovery should use all matching sources
                or only explicitly selected sources.
            </p>

            <div class="mt-4 space-y-3">

                {{-- All matching --}}
                <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                    <input
                        type="radio"
                        value="all"
                        wire:model.live="source_mode"
                        class="mt-1"
                    >

                    <span>
                            <span class="block text-sm font-medium">
                                All matching active sources
                            </span>

                            <span class="mt-1 block text-sm text-gray-500">
                                Automatically use every active source matching
                                this profile's category, GEO and language.
                            </span>
                        </span>
                </label>

                {{-- Selected --}}
                <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                    <input
                        type="radio"
                        value="selected"
                        wire:model.live="source_mode"
                        class="mt-1"
                    >

                    <span>
                            <span class="block text-sm font-medium">
                                Selected sources only
                            </span>

                            <span class="mt-1 block text-sm text-gray-500">
                                Restrict Discovery to sources explicitly
                                selected below.
                            </span>
                        </span>
                </label>

            </div>

            @if ($source_mode === 'selected')
                <div class="mt-5 space-y-5">

                    {{-- Selected sources --}}
                    @if ($this->selectedSources->isNotEmpty())
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <h3 class="text-sm font-semibold">
                                    Selected sources
                                </h3>

                                <span class="text-xs text-gray-500">
                                        {{ $this->selectedSources->count() }} selected
                                    </span>
                            </div>

                            <div class="space-y-2">
                                @foreach ($this->selectedSources as $source)
                                    <div
                                        wire:key="selected-source-{{ $source->id }}"
                                        class="flex items-center justify-between rounded-lg border border-gray-200 bg-gray-50 px-4 py-3"
                                    >
                                        <div>
                                            <div class="text-sm font-medium">
                                                {{ $source->name }}
                                            </div>

                                            <div class="mt-0.5 text-xs text-gray-500">
                                                {{ $source->domain }}
                                                ·
                                                {{ ucfirst($source->status) }}
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            wire:click="removeSource({{ $source->id }})"
                                            class="text-sm font-medium text-red-600 hover:text-red-800"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Search --}}
                    <div class="rounded-lg border border-gray-200 p-4">
                        <label class="mb-2 block text-sm font-medium">
                            Search sources
                        </label>

                        <input
                            type="text"
                            wire:model.live.debounce.300ms="source_search"
                            placeholder="Search by source name or domain..."
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >

                        @if (mb_strlen(trim($source_search)) < 2)
                            <div class="mt-3 text-sm text-gray-500">
                                Enter at least 2 characters to search.
                            </div>
                        @else
                            <div class="mt-4 space-y-2">
                                @forelse ($this->sourceResults as $source)
                                    <label
                                        wire:key="source-result-{{ $source->id }}"
                                        class="flex items-center gap-3 rounded-lg border border-gray-200 p-3"
                                    >
                                        <input
                                            type="checkbox"
                                            value="{{ $source->id }}"
                                            wire:model.live="source_ids"
                                            class="rounded border-gray-300"
                                        >

                                        <span class="text-sm">
                                                <span class="font-medium">
                                                    {{ $source->name }}
                                                </span>

                                                <span class="text-gray-400">
                                                    ({{ $source->domain }})
                                                    ·
                                                    {{ ucfirst($source->status) }}
                                                </span>
                                            </span>
                                    </label>
                                @empty
                                    <div class="text-sm text-gray-500">
                                        No sources found.
                                    </div>
                                @endforelse
                            </div>
                        @endif
                    </div>

                </div>
            @endif
        </div>

        {{-- Generation settings --}}
        <div>
            <h2 class="text-lg font-semibold">
                Generation settings
            </h2>

            <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-3">

                <div>
                    <label class="mb-2 block text-sm font-medium">
                        Freshness
                    </label>

                    <select
                        wire:model="freshness_minutes"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                    >
                        <option value="60">1 hour</option>
                        <option value="360">6 hours</option>
                        <option value="720">12 hours</option>
                        <option value="1440">24 hours</option>
                        <option value="2880">48 hours</option>
                        <option value="4320">3 days</option>
                        <option value="10080">7 days</option>
                    </select>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium">
                        Target markets per run
                    </label>

                    <input
                        type="number"
                        min="1"
                        wire:model="target_markets_per_run"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                    >
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium">
                        Status
                    </label>

                    <select
                        wire:model="status"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                    >
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="disabled">Disabled</option>
                    </select>
                </div>

            </div>

            <p class="mt-3 text-sm text-gray-500">
                Target markets is a generation goal, not a limit on the
                number of articles Discovery may find.
            </p>
        </div>

        {{-- Notes --}}
        <div>
            <label class="mb-2 block text-sm font-medium">
                Notes
            </label>

            <textarea
                wire:model="notes"
                rows="4"
                class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
            ></textarea>
        </div>

        {{-- Actions --}}
        <div class="flex items-center justify-between border-t border-gray-100 pt-6">
            <a
                href="{{ route('admin.generation-profiles.index') }}"
                class="text-sm font-medium text-gray-500 hover:text-gray-900"
            >
                Cancel
            </a>

            @php
                $isEdit = isset($profile);
                $submitMethod = $isEdit ? 'update' : 'save';
            @endphp

            <button
                type="button"
                wire:click="{{ $submitMethod }}"
                wire:loading.attr="disabled"
                wire:target="{{ $submitMethod }}"
                class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span
                    wire:loading.remove
                    wire:target="{{ $submitMethod }}"
                >
                    {{ $isEdit ? 'Update Profile' : 'Save Profile' }}
                </span>

                <span
                    wire:loading
                    wire:target="{{ $submitMethod }}"
                >
                    {{ $isEdit ? 'Updating...' : 'Saving...' }}
                </span>
            </button>
        </div>

    </div>
</div>
