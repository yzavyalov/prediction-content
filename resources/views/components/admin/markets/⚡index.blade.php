<?php

use App\Models\Country;
use App\Models\Market;
use App\Models\MarketImage;
use App\Jobs\GenerateMarketImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $countryId = '';

    public int $perPage = 20;
    public string $jumpPage = '';

    public array $generateSelected = [];
    public array $exportSelected = [];
    public ?int $previewImageId = null;
    public string $imageNotice = '';

    public function toggleGeneratePage(): void
    {
        $ids = $this->marketsQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($this->getPage(), $this->getPerPage())
            ->pluck('id');

        $busyIds = MarketImage::query()
            ->whereIn('market_id', $ids)
            ->whereIn('status', ['pending', 'processing'])
            ->pluck('market_id')
            ->all();

        $readyIds = MarketImage::query()
            ->whereIn('market_id', $ids)
            ->where('is_selected', true)
            ->where('status', 'completed')
            ->whereNotNull('path')
            ->pluck('market_id')
            ->all();

        $eligible = $ids->diff(array_merge($busyIds, $readyIds))
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($eligible === []) {
            return;
        }

        $allChecked = count(array_intersect($eligible, $this->generateSelected)) === count($eligible);

        $this->generateSelected = $allChecked
            ? array_values(array_diff($this->generateSelected, $eligible))
            : array_values(array_unique(array_merge($this->generateSelected, $eligible)));
    }

    public function generateSelectedImages(): void
    {
        if ($this->generateSelected === []) {
            return;
        }

        $this->queueImages($this->generateSelected, false);
        $this->generateSelected = [];
    }



    public function toggleExportPage(): void
    {
        $ids = $this->marketsQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($this->getPage(), $this->getPerPage())
            ->pluck('id');

        $eligible = Market::query()
            ->whereIn('id', $ids)
            ->whereHas('selectedImage')
            ->whereDoesntHave('marketExport', function ($query) {
                $query->whereIn('status', [
                    'completed',
                    'processing',
                ]);
            })
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($eligible === []) {
            return;
        }

        $allChecked = count(
                array_intersect($eligible, $this->exportSelected)
            ) === count($eligible);

        $this->exportSelected = $allChecked
            ? array_values(array_diff($this->exportSelected, $eligible))
            : array_values(array_unique(array_merge(
                $this->exportSelected,
                $eligible
            )));
    }



    public function regenerateImage(int $marketId): void
    {
        $this->queueImages([$marketId], true);
    }

    private function queueImages(array $ids, bool $allowRegeneration): void
    {
        $validIds = collect($ids)
            ->filter(fn ($id) => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $queued = 0;
        $skipped = 0;

        foreach ($validIds as $id) {
            $createdImageId = DB::transaction(function () use ($id, $allowRegeneration): ?int {
                // Lock the market row to serialize concurrent requests for the same market.
                $market = Market::query()->lockForUpdate()->find($id);

                if (!$market) {
                    return null;
                }

                $busy = MarketImage::query()
                    ->where('market_id', $id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->exists();

                if ($busy) {
                    return null;
                }

                if (!$allowRegeneration) {
                    $alreadyReady = MarketImage::query()
                        ->where('market_id', $id)
                        ->where('is_selected', true)
                        ->where('status', 'completed')
                        ->whereNotNull('path')
                        ->exists();

                    if ($alreadyReady) {
                        return null;
                    }
                }

                $market->load('translations');
                $english = $market->translations->first(function ($translation): bool {
                    $locale = strtolower(str_replace('_', '-', trim((string) $translation->locale)));
                    return ($locale === 'en' || str_starts_with($locale, 'en-'))
                        && filled($translation->question);
                });

                $title = trim((string) ($english?->question ?: $market->question));

                if ($title === '') {
                    return null;
                }

                $image = MarketImage::query()->create([
                    'market_id' => $market->id,
                    'provider' => 'gemini',
                    'model' => config('services.gemini.models.image', 'gemini-2.5-flash-image'),
                    'prompt' => 'РЎРѕР·РґР°Р№ СЂРµРєР»Р°РјРЅРѕРµ РёР·РѕР±СЂР°Р¶РµРЅРёРµ СЃ СѓРІРµР»РёС‡РµРЅРЅС‹РјРё Р»РѕРіРѕС‚РёРїР°РјРё РЅР° С‚РµРјСѓ: ' . $title,
                    'disk' => 'public',
                    'status' => 'pending',
                    'attempts' => 0,
                    'is_selected' => false,
                ]);

                GenerateMarketImage::dispatch($image->id)->afterCommit();

                return $image->id;
            });

            if ($createdImageId !== null) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        $this->imageNotice = $queued . ' image generation job(s) queued.'
            . ($skipped > 0 ? ' Skipped: ' . $skipped . ' (already ready, busy, or unavailable).' : '');
    }



    public function exportSelectedMarkets(): void
    {
        $ids = collect($this->exportSelected)
            ->filter(fn ($id) => filter_var(
                    $id,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                ) !== false)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $markets = Market::query()
            ->whereIn('id', $ids)
            ->whereHas('selectedImage')
            ->whereDoesntHave('marketExport', function ($query) {
                $query->whereIn('status', [
                    'completed',
                    'processing',
                ]);
            })
            ->get();

        $queued = 0;

        foreach ($markets as $market) {
            \App\Jobs\ExportMarketToWrangle::dispatch($market->id);
            $queued++;
        }

        $skipped = $ids->count() - $queued;

        $this->exportSelected = [];

        $this->imageNotice = sprintf(
            'Wrangle export: %d queued, %d skipped.',
            $queued,
            $skipped
        );
    }





    public function exportAllReadyMarkets(): void
    {
        $queued = 0;

        Market::query()
            ->whereHas('selectedImage')
            ->whereDoesntHave('marketExport', function ($query) {
                $query->whereIn('status', [
                    'completed',
                    'processing',
                    'pending',
                ]);
            })
            ->chunkById(50, function ($markets) use (&$queued) {
                foreach ($markets as $market) {
                    \App\Jobs\ExportMarketToWrangle::dispatch($market->id);

                    $queued++;
                }
            });

        $this->imageNotice = sprintf(
            'Wrangle export: %d market(s) queued.',
            $queued
        );
    }


    public function openImage(int $imageId): void
    {
        $image = DB::table('market_images')->where('id', $imageId)
            ->where('status', 'completed')->whereNotNull('path')->first();
        $this->previewImageId = $image ? $imageId : null;
    }

    public function closeImage(): void
    {
        $this->previewImageId = null;
    }


    public function updatedSearch(): void
    {
        $this->resetPagination();
    }

    public function updatedStatus(): void
    {
        $this->resetPagination();
    }

    public function updatedCountryId(): void
    {
        $this->resetPagination();
    }

    public function updatedPerPage(): void
    {
        if (!in_array($this->perPage, [20, 50, 100], true)) {
            $this->perPage = 20;
        }

        $this->resetPagination();
    }

    private function resetPagination(): void
    {
        $this->resetPage();

        $this->jumpPage = '';
    }

    private function marketsQuery(): Builder
    {
        return Market::query()
            ->when($this->search !== '', function ($query) {
                $term = '%' . trim($this->search) . '%';

                $query->where(function (Builder $marketQuery) use ($term) {
                    $marketQuery->where('question', 'like', $term)
                        ->orWhereHas('translations', function (Builder $translationQuery) use ($term) {
                            $translationQuery->where('question', 'like', $term);
                        });
                });
            })
            ->when($this->status !== '', function ($query) {
                $query->where('status', $this->status);
            })
            ->when($this->countryId !== '', function ($query) {
                $query->whereHas('event', function ($eventQuery) {
                    $eventQuery->where(
                        'country_id',
                        $this->countryId
                    );
                });
            });
    }

    private function getPerPage(): int
    {
        return in_array($this->perPage, [20, 50, 100], true)
            ? $this->perPage
            : 20;
    }

    public function jumpToPage(): void
    {
        $page = filter_var(
            $this->jumpPage,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($page === false) {
            return;
        }

        $total = $this->marketsQuery()->count();

        $lastPage = max(
            1,
            (int) ceil($total / $this->getPerPage())
        );

        $page = min($page, $lastPage);

        $this->setPage($page);

        $this->jumpPage = '';
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->countryId = '';

        $this->resetPagination();
    }

    public function with(): array
    {
        $page = $this->marketsQuery()
            ->with(['event.country', 'translations', 'marketExport'])
            ->withCount('outcomes')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->getPerPage());

        $images = DB::table('market_images')
            ->whereIn('market_id', $page->getCollection()->pluck('id'))
            ->orderByDesc('id')->get()->groupBy('market_id');

        $preview = $this->previewImageId
            ? DB::table('market_images')->find($this->previewImageId)
            : null;

        return [
            'markets' => $page,
            'marketImages' => $images,
            'previewImageUrl' => $preview && $preview->path && $preview->status === 'completed'
                ? asset('storage/' . ltrim($preview->path, '/'))
                : null,

            'countries' => Country::query()
                ->orderBy('name')
                ->get(),

            'statuses' => Market::query()
                ->distinct()
                ->orderBy('status')
                ->pluck('status'),
        ];
    }
};
?>

<div wire:poll.5s>

    {{-- Page header --}}

    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">

        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                Markets
            </h1>

            <p class="mt-2 text-sm text-gray-500">
                Manage prediction markets and their outcomes.
            </p>
        </div>

        <a
            href="{{ route('admin.markets.create') }}"
            class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-gray-800"
        >
            <svg
                class="h-4 w-4"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 4v16m8-8H4"
                />
            </svg>

            Add Market
        </a>

    </div>


    {{-- Filters --}}

    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">

        <div class="grid gap-4 md:grid-cols-3">

            {{-- Search --}}

            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">
                    Search
                </label>

                <div class="relative">

                    <svg
                        class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="m21 21-4.35-4.35M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"
                        />
                    </svg>

                    <input
                        type="text"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Search markets..."
                        class="w-full rounded-lg border border-gray-300 py-2.5 pl-10 pr-3 text-sm focus:border-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-500"
                    >

                </div>
            </div>


            {{-- Status --}}

            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">
                    Status
                </label>

                <select
                    wire:model.live="status"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm focus:border-gray-500 focus:outline-none"
                >
                    <option value="">
                        All statuses
                    </option>

                    @foreach ($statuses as $item)
                        <option value="{{ $item }}">
                            {{ ucfirst($item) }}
                        </option>
                    @endforeach
                </select>
            </div>


            {{-- Country --}}

            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">
                    Country / GEO
                </label>

                <select
                    wire:model.live="countryId"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm focus:border-gray-500 focus:outline-none"
                >
                    <option value="">
                        All countries
                    </option>

                    @foreach ($countries as $country)
                        <option value="{{ $country->id }}">
                            {{ $country->name }}
                        </option>
                    @endforeach
                </select>
            </div>

        </div>


        {{-- Reset filters --}}

        @if ($search !== '' || $status !== '' || $countryId !== '')

            <div class="mt-4 flex justify-end">

                <button
                    type="button"
                    wire:click="resetFilters"
                    class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 transition hover:text-gray-900"
                >
                    <svg
                        class="h-4 w-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18 18 6M6 6l12 12"
                        />
                    </svg>

                    Reset filters
                </button>

            </div>

        @endif

    </div>


    {{-- Results toolbar --}}

    <div class="mb-4 flex flex-wrap items-center justify-between gap-4">

        <div class="text-sm text-gray-500">

            <span class="text-lg font-semibold text-gray-900">
                {{ number_format($markets->total()) }}
            </span>

            markets found

        </div>

        <div class="flex items-center gap-3">

            <span class="text-sm text-gray-500">
                Rows per page:
            </span>

            <select
                wire:model.live="perPage"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 focus:outline-none"
            >
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>

        </div>

    </div>


    {{-- Image operations --}}
    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <button type="button" wire:click="generateSelectedImages" wire:loading.attr="disabled"
                @disabled(count($generateSelected) === 0)
                class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-40">
            Generate selected ({{ count($generateSelected) }})
        </button>
        <button type="button" wire:click="exportSelectedMarkets" wire:loading.attr="disabled"
                @disabled(count($exportSelected) === 0)
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800 disabled:opacity-40">
            Export selected ({{ count($exportSelected) }})
        </button>
        <button type="button" wire:click="exportAllReadyMarkets" wire:loading.attr="disabled"
                class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-40">
            Export all ready
        </button>
        <span class="text-xs text-gray-500">Export is pending Wrangle.win API integration.</span>
        @if ($imageNotice !== '')
            <div class="w-full text-sm text-gray-600" role="status">{{ $imageNotice }}</div>
        @endif
    </div>


    {{-- Markets table --}}

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">

        <table class="w-full text-left">

            <thead class="border-b border-gray-200 bg-gray-50">

            <tr>
                <th class="px-3 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <button type="button" wire:click="toggleGeneratePage" title="Select markets on current page for image generation">Generate</button>
                </th>
                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    Market
                </th>

                <th class="whitespace-nowrap px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    GEO
                </th>

                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    Languages
                </th>

                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    Image
                </th>

                <th class="px-3 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    <button type="button" wire:click="toggleExportPage" title="Select ready images on current page">Export</button>
                </th>

                <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-gray-500">
                    Actions
                </th>

            </tr>

            </thead>


            <tbody class="divide-y divide-gray-100">

            @forelse ($markets as $market)
                @php
                    $normalizeLocale = static fn ($locale) => strtolower(str_replace('_', '-', trim((string) $locale)));
                    $sourceLocale = $normalizeLocale($market->source_locale);
                    $translations = $market->translations->filter(fn ($translation) => filled($translation->question));
                    $englishTranslation = $translations->first(fn ($translation) => $normalizeLocale($translation->locale) === 'en')
                        ?? $translations->first(fn ($translation) => str_starts_with($normalizeLocale($translation->locale), 'en-'));
                    $sourceIsEnglish = $sourceLocale === 'en' || str_starts_with($sourceLocale, 'en-');
                    $englishQuestion = $englishTranslation?->question ?? ($sourceIsEnglish ? $market->question : null);
                    $imageRows = $marketImages->get($market->id, collect());
                    $selectedImage = $imageRows->first(fn ($img) => $img->is_selected && $img->status === 'completed' && filled($img->path));
                    $latestImage = $imageRows->first();
                    $displayImage = $selectedImage ?? ($latestImage?->status === 'completed' ? $latestImage : null);
                    $imageBusy = $imageRows->contains(fn ($img) => in_array($img->status, ['pending', 'processing'], true));
                    $languages = $translations->pluck('locale')
                        ->push($sourceLocale)
                        ->map($normalizeLocale)
                        ->filter()
                        ->unique()
                        ->sortBy(fn ($locale) => ($locale === 'en' ? '0' : '1') . $locale)
                        ->values();
                @endphp


                <tr
                    wire:key="market-{{ $market->id }}"
                    class="transition-colors hover:bg-gray-50"
                >

                    {{-- Generate checkbox --}}
                    <td class="px-3 py-4 text-center">
                        <input type="checkbox" wire:model.live="generateSelected" value="{{ $market->id }}"
                               @disabled($imageBusy || (bool) $selectedImage)
                               class="rounded border-gray-300 disabled:opacity-30"
                               aria-label="Generate image for market {{ $market->id }}">
                    </td>

                    {{-- Market --}}

                    <td class="px-6 py-4">

                        <a
                            href="{{ route('admin.markets.show', $market) }}"
                            class="font-medium leading-relaxed text-gray-900 hover:underline"
                        >
                            {{ $englishQuestion ?? $market->question }}
                        </a>

                        @if (blank($englishQuestion))
                            <div class="mt-1 text-xs text-amber-700">English translation missing</div>
                        @endif

                        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">

                                <span>
                                    #{{ $market->id }}
                                </span>

                            <span class="text-gray-300">
                                    &bull;
                                </span>

                            <span>
                                    Event #{{ $market->event_id }}
                                </span>

                            @if ($sourceLocale !== '')
                                <span class="text-gray-500" title="Original language">
                                    Source: {{ strtoupper($sourceLocale) }}
                                </span>
                            @endif

                        </div>

                    </td>


                    {{-- GEO --}}

                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">

                        {{ $market->event?->country?->name ?? '-' }}

                    </td>


                    {{-- Languages --}}
                    <td class="px-6 py-4 text-sm">
                        <div class="flex flex-wrap gap-1">
                            @forelse ($languages as $locale)
                                <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700">
                                    {{ strtoupper($locale) }}
                                </span>
                            @empty
                                <span class="text-gray-400">-</span>
                            @endforelse
                        </div>
                    </td>

                    {{-- Image --}}
                    <td class="px-6 py-4 text-sm">
                        @if ($displayImage)
                            <button type="button" wire:click="openImage({{ $displayImage->id }})"
                                    title="Enlarge image" class="block overflow-hidden rounded-lg border border-gray-200">
                                <img src="{{ asset('storage/' . ltrim($displayImage->path, '/')) }}"
                                     alt="Market image {{ $market->id }}" class="h-16 w-28 object-cover" loading="lazy">
                            </button>
                        @else
                            <div class="flex h-16 w-28 items-center justify-center rounded-lg bg-gray-100 text-xs text-gray-400">No image</div>
                        @endif
                        <div class="mt-1 text-xs text-gray-500">{{ $latestImage?->status ?? 'Not generated' }}</div>
                        @if ($latestImage?->status === 'failed')
                            <div class="mt-1 max-w-40 break-words text-xs text-red-600" title="{{ $latestImage->error_message }}">Generation failed</div>
                        @endif
                    </td>


                    {{-- Export checkbox and status --}}
                    <td class="px-3 py-4 text-center">
                        @php
                            $export = $market->marketExport;
                            $exportStatus = $export?->status ?? 'not_exported';

                            $canExport = (bool) $selectedImage
                                && !in_array($exportStatus, [
                                    'completed',
                                    'processing',
                                ], true);
                        @endphp

                        <div class="flex flex-col items-center gap-2">
                            <input
                                type="checkbox"
                                wire:model.live="exportSelected"
                                value="{{ $market->id }}"
                                @disabled(!$canExport)
                                class="rounded border-gray-300 disabled:opacity-30"
                                aria-label="Export market {{ $market->id }}"
                            >

                            @if ($exportStatus === 'completed')
                                <span class="rounded bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                                    Completed
                                </span>

                                @if ($export->remote_bet_id)
                                    <span class="text-xs text-gray-500">
                                        Bet #{{ $export->remote_bet_id }}
                                    </span>
                                @endif

                            @elseif ($exportStatus === 'processing')
                                <span class="rounded bg-blue-100 px-2 py-1 text-xs font-medium text-blue-800">
                                    Processing
                                </span>

                            @elseif ($exportStatus === 'failed')
                                <span
                                    class="rounded bg-red-100 px-2 py-1 text-xs font-medium text-red-800"
                                    title="{{ $export->error_message }}"
                                >
                                    Failed
                                </span>

                            @elseif ($exportStatus === 'pending')
                                <span class="rounded bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800">
                                    Pending
                                </span>

                            @else
                                <span class="text-xs text-gray-400">
                                    Not exported
                                </span>
                            @endif
                        </div>
                    </td>


                    {{-- Actions --}}

                    <td class="whitespace-nowrap px-6 py-4 text-sm">

                        <div class="flex items-center gap-2">

                            <a
                                href="{{ route('admin.markets.show', $market) }}"
                                class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:border-gray-400 hover:bg-white"
                            >
                                View
                            </a>

                            <a
                                href="{{ route('admin.markets.edit', $market) }}"
                                class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 transition hover:bg-gray-200"
                            >
                                Edit
                            </a>
                            <button type="button" wire:click="regenerateImage({{ $market->id }})"
                                    wire:loading.attr="disabled" @disabled($imageBusy)
                                    class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 disabled:opacity-40">
                                {{ $selectedImage ? 'Regenerate' : 'Generate' }}
                            </button>

                        </div>

                    </td>

                </tr>

            @empty

                <tr>
                    <td colspan="7" class="px-6 py-16 text-center">

                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100">

                            <svg
                                class="h-7 w-7 text-gray-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="1.5"
                                    d="M9 17v-4m3 4V7m3 10v-6M4 19h16"
                                />
                            </svg>

                        </div>

                        <div class="font-medium text-gray-900">
                            No markets found
                        </div>

                        <div class="mt-2 text-sm text-gray-500">
                            Try changing your search or filters.
                        </div>

                        @if ($search !== '' || $status !== '' || $countryId !== '')

                            <button
                                type="button"
                                wire:click="resetFilters"
                                class="mt-4 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            >
                                Clear filters
                            </button>

                        @endif

                    </td>
                </tr>

            @endforelse

            </tbody>

        </table>

    </div>


    {{-- Image preview --}}
    @if ($previewImageUrl)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-6"
             role="dialog" aria-modal="true" aria-label="Market image preview">
            <div class="relative max-h-full max-w-5xl">
                <button type="button" wire:click="closeImage"
                        class="absolute right-2 top-2 rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-900">Close</button>
                <img src="{{ $previewImageUrl }}" alt="Market image preview"
                     class="max-h-[85vh] max-w-full rounded-lg object-contain">
            </div>
        </div>
    @endif

    {{-- Pagination --}}

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">

        <div class="flex flex-wrap items-center justify-between gap-4">

            {{-- Total information --}}

            <div class="text-sm text-gray-500">

                Showing

                <span class="font-semibold text-gray-900">
                    {{ number_format($markets->firstItem() ?? 0) }}
                </span>

                -

                <span class="font-semibold text-gray-900">
                    {{ number_format($markets->lastItem() ?? 0) }}
                </span>

                of

                <span class="font-semibold text-gray-900">
                    {{ number_format($markets->total()) }}
                </span>

                markets

            </div>


            {{-- Page information --}}

            <div class="rounded-lg bg-gray-100 px-3 py-1.5 text-sm text-gray-600">

                Page

                <span class="font-semibold text-gray-900">
                    {{ number_format($markets->currentPage()) }}
                </span>

                of

                <span class="font-semibold text-gray-900">
                    {{ number_format($markets->lastPage()) }}
                </span>

            </div>

        </div>


        @if ($markets->hasPages())

            @php

                $currentPage = $markets->currentPage();
                $lastPage = $markets->lastPage();

                $startPage = max(1, $currentPage - 2);
                $endPage = min($lastPage, $currentPage + 2);

                if ($currentPage <= 3) {
                    $endPage = min($lastPage, 5);
                }

                if ($currentPage >= $lastPage - 2) {
                    $startPage = max(1, $lastPage - 4);
                }

                $pages = collect([1, $lastPage])
                    ->merge(range($startPage, $endPage))
                    ->unique()
                    ->sort()
                    ->values();

                $previousPageNumber = null;

            @endphp


            <div class="mt-5 border-t border-gray-100 pt-5">

                <nav
                    aria-label="Markets pagination"
                    class="flex flex-wrap items-center justify-center gap-1.5"
                >

                    {{-- First page --}}

                    <button
                        type="button"
                        wire:click="gotoPage(1)"
                        wire:loading.attr="disabled"
                        @disabled($markets->onFirstPage())
                        title="First page"
                        aria-label="First page"
                        class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 transition hover:border-gray-400 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-30"
                    >

                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="m11 19-7-7 7-7m9 14-7-7 7-7"
                            />
                        </svg>

                    </button>


                    {{-- Previous page --}}

                    <button
                        type="button"
                        wire:click="previousPage"
                        wire:loading.attr="disabled"
                        @disabled($markets->onFirstPage())
                        title="Previous page"
                        aria-label="Previous page"
                        class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 transition hover:border-gray-400 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-30"
                    >

                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="m15 19-7-7 7-7"
                            />
                        </svg>

                    </button>


                    {{-- Page numbers --}}

                    @foreach ($pages as $page)

                        @if (
                            $previousPageNumber !== null
                            && $page > $previousPageNumber + 1
                        )

                            <span class="flex h-10 items-center justify-center px-2 text-sm font-medium text-gray-400">
                                &hellip;
                            </span>

                        @endif


                        <button
                            type="button"
                            wire:key="markets-page-{{ $page }}"
                            wire:click="gotoPage({{ $page }})"
                            wire:loading.attr="disabled"
                            @if ($currentPage === $page)
                                aria-current="page"
                            @endif
                            @class([
                                'flex h-10 min-w-10 items-center justify-center rounded-lg px-3 text-sm font-medium transition',

                                'bg-gray-900 text-white shadow-sm' =>
                                    $currentPage === $page,

                                'border border-gray-200 bg-white text-gray-700 hover:border-gray-400 hover:bg-gray-50' =>
                                    $currentPage !== $page,
                            ])
                        >
                            {{ number_format($page) }}
                        </button>

                        @php
                            $previousPageNumber = $page;
                        @endphp

                    @endforeach


                    {{-- Next page --}}

                    <button
                        type="button"
                        wire:click="nextPage"
                        wire:loading.attr="disabled"
                        @disabled(!$markets->hasMorePages())
                        title="Next page"
                        aria-label="Next page"
                        class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 transition hover:border-gray-400 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-30"
                    >

                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="m9 5 7 7-7 7"
                            />
                        </svg>

                    </button>


                    {{-- Last page --}}

                    <button
                        type="button"
                        wire:click="gotoPage({{ $lastPage }})"
                        wire:loading.attr="disabled"
                        @disabled(!$markets->hasMorePages())
                        title="Last page"
                        aria-label="Last page"
                        class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 transition hover:border-gray-400 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-30"
                    >

                        <svg
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="1.8"
                                d="m4 5 7 7-7 7m9-14 7 7-7 7"
                            />
                        </svg>

                    </button>

                </nav>


                {{-- Jump to page --}}

                <div class="mt-5 flex justify-center">

                    <form
                        wire:submit="jumpToPage"
                        class="flex flex-wrap items-center justify-center gap-2"
                    >

                        <label
                            for="markets-jump-page"
                            class="mr-1 text-sm text-gray-500"
                        >
                            Go to page:
                        </label>

                        <input
                            id="markets-jump-page"
                            type="number"
                            min="1"
                            max="{{ $lastPage }}"
                            step="1"
                            required
                            wire:model="jumpPage"
                            placeholder="{{ $currentPage }}"
                            class="h-9 w-24 rounded-lg border border-gray-300 px-3 text-center text-sm text-gray-700 focus:border-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-500"
                        >

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="h-9 rounded-lg bg-gray-900 px-4 text-sm font-medium text-white transition hover:bg-gray-800 disabled:opacity-50"
                        >
                            Go
                        </button>

                    </form>

                </div>

            </div>

        @endif

    </div>

</div>
