<?php

use App\Models\Country;
use App\Models\Market;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $countryId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedCountryId(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'markets' => Market::query()
                ->with(['event.country'])
                ->withCount('outcomes')
                ->when($this->search !== '', function ($query) {
                    $query->where(
                        'question',
                        'like',
                        '%' . $this->search . '%'
                    );
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
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20),

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

<div>
    <div class="mb-8 flex items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold">
                Markets
            </h1>

            <p class="mt-2 text-gray-500">
                Manage prediction markets and their outcomes.
            </p>
        </div>

        <a
            href="{{ route('admin.markets.create') }}"
            class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800"
        >
            Add Market
        </a>
    </div>

    <div class="mb-6 grid gap-4 rounded-xl bg-white p-5 shadow-sm md:grid-cols-3">

        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">
                Search
            </label>

            <input
                type="text"
                wire:model.live.debounce.400ms="search"
                placeholder="Search markets..."
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            >
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">
                Status
            </label>

            <select
                wire:model.live="status"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            >
                <option value="">All statuses</option>

                @foreach ($statuses as $item)
                    <option value="{{ $item }}">
                        {{ ucfirst($item) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">
                Country / GEO
            </label>

            <select
                wire:model.live="countryId"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            >
                <option value="">All countries</option>

                @foreach ($countries as $country)
                    <option value="{{ $country->id }}">
                        {{ $country->name }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm">
        <table class="w-full text-left">
            <thead class="border-b border-gray-200 bg-gray-50">
            <tr>
                <th class="px-6 py-4 text-sm font-medium text-gray-600">
                    Market
                </th>

                <th class="px-6 py-4 text-sm font-medium text-gray-600">
                    GEO
                </th>

                <th class="px-6 py-4 text-sm font-medium text-gray-600">
                    Status
                </th>

                <th class="px-6 py-4 text-sm font-medium text-gray-600">
                    Outcomes
                </th>

                <th class="px-6 py-4 text-sm font-medium text-gray-600">
                    Finish At
                </th>

                <th class="px-6 py-4 text-sm font-medium text-gray-600">
                    Actions
                </th>
            </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
            @forelse ($markets as $market)
                <tr wire:key="market-{{ $market->id }}">
                    <td class="px-6 py-4">
                        <a
                            href="{{ route('admin.markets.show', $market) }}"
                            class="font-medium text-gray-900 hover:underline"
                        >
                            {{ $market->question }}
                        </a>

                        <div class="mt-1 text-xs text-gray-500">
                            #{{ $market->id }}
                            · Event #{{ $market->event_id }}
                            · {{ strtoupper($market->source_locale) }}
                        </div>
                    </td>

                    <td class="px-6 py-4 text-sm">
                        {{ $market->event?->country?->name ?? '—' }}
                    </td>

                    <td class="px-6 py-4 text-sm">
                            <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                                {{ ucfirst($market->status) }}
                            </span>
                    </td>

                    <td class="px-6 py-4 text-sm">
                        {{ $market->outcomes_count }}
                    </td>

                    <td class="whitespace-nowrap px-6 py-4 text-sm">
                        {{ $market->finish_at?->format('Y-m-d H:i') ?? 'Not set' }}
                    </td>

                    <td class="px-6 py-4 text-sm">
                        <div class="flex gap-4">
                            <a
                                href="{{ route('admin.markets.show', $market) }}"
                                class="font-medium text-gray-700 hover:underline"
                            >
                                View
                            </a>

                            <a
                                href="{{ route('admin.markets.edit', $market) }}"
                                class="font-medium text-gray-700 hover:underline"
                            >
                                Edit
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                        No markets found.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $markets->links() }}
    </div>
</div>
