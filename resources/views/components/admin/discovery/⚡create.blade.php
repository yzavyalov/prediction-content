<?php

use App\Jobs\ExecuteDiscoveryRun;
use App\Models\Category;
use App\Models\Country;
use App\Models\CountryGroup;
use App\Models\GenerationProfile;
use App\Models\Source;
use App\Services\Discovery\DiscoveryRunService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $sourceId = '';
    public string $profileId = '';
    public string $categoryId = '';
    public string $geoType = 'country';
    public string $geoId = '';

    public string $windowFrom = '';
    public string $windowTo = '';

    public int $maxResults = 10;

    public function mount(): void
    {
        $this->windowFrom = now()
            ->subDay()
            ->format('Y-m-d\TH:i');

        $this->windowTo = now()
            ->format('Y-m-d\TH:i');
    }

    public function updatedSourceId(): void
    {
        $this->categoryId = '';
        $this->geoId = '';
    }

    public function updatedProfileId(): void
    {
        $this->categoryId = '';
        $this->geoId = '';
    }

    public function updatedGeoType(): void
    {
        $this->geoId = '';
    }

    public function with(): array
    {
        $source = $this->sourceId !== ''
            ? Source::find($this->sourceId)
            : null;

        $profile = $this->profileId !== ''
            ? GenerationProfile::find($this->profileId)
            : null;

        $categories = collect();
        $geos = collect();

        if ($source && $profile) {
            $profileCategoryIds = $profile->categories()
                ->pluck('categories.id');

            $categories = $source->categories()
                ->whereIn('categories.id', $profileCategoryIds)
                ->where('categories.is_active', true)
                ->orderBy('categories.name')
                ->get();

            if ($this->geoType === 'country') {
                $profileGeoIds = $profile->countries()
                    ->pluck('countries.id');

                $geos = $source->countries()
                    ->whereIn('countries.id', $profileGeoIds)
                    ->where('countries.is_active', true)
                    ->orderBy('countries.name')
                    ->get();
            } else {
                $profileGeoIds = $profile->countryGroups()
                    ->pluck('country_groups.id');

                $geos = $source->countryGroups()
                    ->whereIn('country_groups.id', $profileGeoIds)
                    ->where('country_groups.is_active', true)
                    ->orderBy('country_groups.name')
                    ->get();
            }
        }

        return [
            'sources' => Source::orderBy('name')->get(),
            'profiles' => GenerationProfile::orderBy('name')->get(),
            'categories' => $categories,
            'geos' => $geos,
        ];
    }

    public function createRun(DiscoveryRunService $service)
    {
        $validated = $this->validate([
            'sourceId' => ['required', 'integer', 'exists:sources,id'],
            'profileId' => ['required', 'integer', 'exists:generation_profiles,id'],
            'categoryId' => ['required', 'integer', 'exists:categories,id'],
            'geoType' => ['required', 'in:country,country_group'],
            'geoId' => ['required', 'integer'],
            'windowFrom' => ['required', 'date'],
            'windowTo' => ['required', 'date', 'after:windowFrom'],
            'maxResults' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        $source = Source::findOrFail($validated['sourceId']);
        $profile = GenerationProfile::findOrFail($validated['profileId']);
        $category = Category::findOrFail($validated['categoryId']);

        $geo = $validated['geoType'] === 'country'
            ? Country::findOrFail($validated['geoId'])
            : CountryGroup::findOrFail($validated['geoId']);

        $categoryAllowed = $source->categories()
                ->whereKey($category->id)
                ->exists()
            && $profile->categories()
                ->whereKey($category->id)
                ->exists()
            && $category->is_active;

        $sourceAllowed = $profile->sources()
            ->whereKey($source->id)
            ->exists();

        $geoAllowed = $validated['geoType'] === 'country'
            ? (
                $source->countries()->whereKey($geo->id)->exists()
                && $profile->countries()->whereKey($geo->id)->exists()
            )
            : (
                $source->countryGroups()->whereKey($geo->id)->exists()
                && $profile->countryGroups()->whereKey($geo->id)->exists()
            );

        if (!$sourceAllowed) {
            throw ValidationException::withMessages([
                'sourceId' => 'Source is not assigned to this generation profile.',
            ]);
        }

        if (!$categoryAllowed) {
            throw ValidationException::withMessages([
                'categoryId' => 'Category is not available for this source and profile.',
            ]);
        }

        if (!$geoAllowed || !$geo->is_active) {
            throw ValidationException::withMessages([
                'geoId' => 'GEO is not available for this source and profile.',
            ]);
        }

        $run = $service->create(
            profile: $profile,
            source: $source,
            category: $category,
            geo: $geo,
            from: Carbon::parse($validated['windowFrom']),
            to: Carbon::parse($validated['windowTo']),
        );

        try {
            ExecuteDiscoveryRun::dispatch(
                $run->id,
                $validated['maxResults'],
            )->onConnection('database');
        } catch (\Throwable $e) {
            $run->delete();

            report($e);

            throw ValidationException::withMessages([
                'sourceId' => 'Unable to queue Discovery Run. Please try again.',
            ]);
        }

        session()->flash(
            'success',
            "Discovery Run #{$run->id} has been queued."
        );

        return redirect()->route('admin.discovery.index');
    }
};
?>

<div class="mx-auto max-w-4xl space-y-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">
                New Discovery Run
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                Configure a new AI-powered article discovery execution.
            </p>
        </div>

        <a
            href="{{ route('admin.discovery.index') }}"
            class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
        >
            Back
        </a>
    </div>

    <form
        wire:submit="createRun"
        class="space-y-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
    >

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">

            <div>
                <label class="mb-2 block text-sm font-medium text-slate-700">
                    Source
                </label>

                <select
                    wire:model.live="sourceId"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                    <option value="">Select source</option>

                    @foreach ($sources as $source)
                        <option value="{{ $source->id }}">
                            {{ $source->name }}
                        </option>
                    @endforeach
                </select>

                @error('sourceId')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium text-slate-700">
                    Generation Profile
                </label>

                <select
                    wire:model.live="profileId"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                    <option value="">Select profile</option>

                    @foreach ($profiles as $profile)
                        <option value="{{ $profile->id }}">
                            {{ $profile->name }}
                        </option>
                    @endforeach
                </select>

                @error('profileId')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium text-slate-700">
                    Category
                </label>

                <select
                    wire:model="categoryId"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                    <option value="">Select category</option>

                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>

                @error('categoryId')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium text-slate-700">
                    GEO Type
                </label>

                <select
                    wire:model.live="geoType"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                    <option value="country">Country</option>
                    <option value="country_group">Country Group</option>
                </select>

                @error('geoType')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-2">
                <label class="mb-2 block text-sm font-medium text-slate-700">
                    GEO
                </label>

                <select
                    wire:model="geoId"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                    <option value="">Select GEO</option>

                    @foreach ($geos as $geo)
                        <option value="{{ $geo->id }}">
                            {{ $geo->name }}
                        </option>
                    @endforeach
                </select>

                @error('geoId')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

        </div>

        <div class="border-t border-slate-200 pt-5">
            <h2 class="mb-4 text-lg font-semibold text-slate-900">
                Discovery Settings
            </h2>

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">

                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">
                        Window From
                    </label>

                    <input
                        type="datetime-local"
                        wire:model="windowFrom"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    >

                    @error('windowFrom')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">
                        Window To
                    </label>

                    <input
                        type="datetime-local"
                        wire:model="windowTo"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    >

                    @error('windowTo')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">
                        Max Results
                    </label>

                    <input
                        type="number"
                        min="1"
                        max="50"
                        wire:model="maxResults"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2"
                    >

                    @error('maxResults')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

            </div>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-slate-200 pt-5">

            <a
                href="{{ route('admin.discovery.index') }}"
                class="rounded-lg border border-slate-300 px-4 py-2 text-sm"
            >
                Cancel
            </a>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="createRun"
                class="rounded-lg bg-indigo-600 px-5 py-2 font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="createRun">
                    Start Discovery
                </span>

                <span wire:loading wire:target="createRun">
                    Creating...
                </span>
            </button>

        </div>

    </form>
</div>
