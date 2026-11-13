<?php

use App\Jobs\ProcessEventMarkets;
use App\Models\Category;
use App\Models\Country;
use App\Models\Event;
use App\Services\Markets\MarketGenerationRunService;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $categoryId = '';
    public string $countryId = '';
    public ?string $generationMessage = null;
    public ?string $generationError = null;

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedStatus(): void { $this->resetPage(); }
    public function updatedCategoryId(): void { $this->resetPage(); }
    public function updatedCountryId(): void { $this->resetPage(); }

    public function generateMarkets(int $eventId, MarketGenerationRunService $service): void
    {
        $this->generationMessage = null;
        $this->generationError = null;

        try {
            $event = Event::query()->findOrFail($eventId);

            // Reuse the existing service, including its run-reuse logic.
            $run = $service->create(event: $event, generationRunId: null);

            if ($run->status === 'pending') {
                ProcessEventMarkets::dispatch(marketGenerationRunId: $run->id);
                $this->generationMessage = "Market generation #{$run->id} queued for Event #{$event->id}.";
                return;
            }

            $this->generationMessage = "Market generation #{$run->id} is already {$run->status}.";
        } catch (\Throwable $e) {
            report($e);
            $this->generationError = 'Could not queue market generation. Check application logs.';
        }
    }

    public function with(): array
    {
        return [
            'events' => Event::query()
                ->with(['category', 'country'])
                ->withCount(['sourceArticles', 'markets'])
                ->with(['marketGenerationRuns' => fn ($query) => $query->latest('id')])
                ->when($this->search !== '', function ($query) {
                    $query->where(function ($query) {
                        $query->where('title', 'like', '%' . $this->search . '%')
                            ->orWhere('description', 'like', '%' . $this->search . '%');
                    });
                })
                ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
                ->when($this->categoryId !== '', fn ($query) => $query->where('category_id', $this->categoryId))
                ->when($this->countryId !== '', fn ($query) => $query->where('country_id', $this->countryId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20),
            'categories' => Category::query()->orderBy('name')->get(),
            'countries' => Country::query()->orderBy('name')->get(),
            'statuses' => Event::query()->distinct()->orderBy('status')->pluck('status'),
        ];
    }
};
?>

<div>
    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold">Events</h1>
            <p class="mt-2 text-gray-500">Manage discovered events and their prediction markets.</p>
        </div>
        <a href="{{ route('admin.events.create') }}"
           class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800">
            Add Event
        </a>
    </div>

    @if ($generationMessage)
        <div class="mb-5 rounded-lg bg-green-50 p-4 text-sm text-green-800">{{ $generationMessage }}</div>
    @endif
    @if ($generationError)
        <div class="mb-5 rounded-lg bg-red-50 p-4 text-sm text-red-800">{{ $generationError }}</div>
    @endif

    <div class="mb-6 grid gap-4 rounded-xl bg-white p-5 shadow-sm md:grid-cols-4">
        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Search</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Search events..."
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Status</label>
            <select wire:model.live="status" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $item)
                    <option value="{{ $item }}">{{ ucfirst($item) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Category</label>
            <select wire:model.live="categoryId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Country</label>
            <select wire:model.live="countryId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <option value="">All countries</option>
                @foreach ($countries as $country)
                    <option value="{{ $country->id }}">{{ $country->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm" wire:poll.5s>
        <table class="w-full text-left">
            <thead class="border-b border-gray-200 bg-gray-50">
            <tr>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">Event</th>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">GEO</th>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">Category</th>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">Event Date</th>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">Status</th>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">Articles</th>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">Markets</th>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">Actions</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            @forelse ($events as $event)
                @php
                    $marketRun = $event->marketGenerationRuns->first();
                    $marketStatus = $marketRun?->status;
                @endphp
                <tr wire:key="event-{{ $event->id }}">
                    <td class="px-6 py-4">
                        <a href="{{ route('admin.events.show', $event) }}" class="font-medium text-gray-900 hover:underline">
                            {{ $event->title }}
                        </a>
                        <div class="mt-1 text-xs text-gray-500">
                            #{{ $event->id }} &middot; {{ strtoupper($event->source_locale ?? '-') }}
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm">{{ $event->country?->name ?? '-' }}</td>
                    <td class="px-6 py-4 text-sm">{{ $event->category?->name ?? '-' }}</td>
                    <td class="px-6 py-4 text-sm whitespace-nowrap">{{ $event->event_at?->format('Y-m-d H:i') ?? 'Unknown' }}</td>
                    <td class="px-6 py-4 text-sm">
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                            {{ ucfirst($event->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm">{{ $event->source_articles_count }}</td>
                    <td class="px-6 py-4 text-sm">{{ $event->markets_count }}</td>
                    <td class="px-6 py-4 text-sm">
                        <div class="flex flex-col items-start gap-2">
                            <a href="{{ route('admin.events.edit', $event) }}" class="font-medium text-gray-700 hover:underline">Edit</a>
                            @if ($marketStatus === null)
                                <button type="button"
                                        wire:click="generateMarkets({{ $event->id }})"
                                        wire:confirm="Generate markets for Event #{{ $event->id }}? This queues a paid Gemini request."
                                        wire:loading.attr="disabled"
                                        wire:target="generateMarkets({{ $event->id }})"
                                        class="whitespace-nowrap rounded-lg bg-blue-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50">
                                    Generate Markets
                                </button>
                            @else
                                <span class="text-xs text-gray-600">Generation: {{ ucfirst($marketStatus) }}</span>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-6 py-12 text-center text-gray-500">No events found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $events->links() }}</div>
</div>
