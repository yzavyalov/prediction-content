<?php

use App\Models\DiscoveryRun;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $status = 'all';

    public string $search = '';

    public ?int $selectedRunId = null;

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function selectRun(int $id): void
    {
        $this->selectedRunId = $id;
    }

    public function closeDetails(): void
    {
        $this->selectedRunId = null;
    }

    public function with(): array
    {
        $query = DiscoveryRun::query()
            ->with([
                'source',
                'category',
                'generationProfile',
            ]);

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if (trim($this->search) !== '') {
            $search = trim($this->search);

            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('source', function (Builder $source) use ($search) {
                    $source->where(
                        'name',
                        'like',
                        '%' . $search . '%'
                    );
                });

                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        $selectedRun = null;

        if ($this->selectedRunId !== null) {
            $selectedRun = DiscoveryRun::query()
                ->with([
                    'source',
                    'category',
                    'generationProfile',
                    'sourceArticles',
                ])
                ->find($this->selectedRunId);
        }

        return [
            'runs' => $query
                ->latest('id')
                ->paginate(15),

            'selectedRun' => $selectedRun,

            'stats' => [
                'total' => DiscoveryRun::count(),

                'completed' => DiscoveryRun::where(
                    'status',
                    'completed'
                )->count(),

                'failed' => DiscoveryRun::where(
                    'status',
                    'failed'
                )->count(),

                'articles' => DiscoveryRun::sum(
                    'accepted_count'
                ),

                'searches' => DiscoveryRun::sum(
                    'google_search_count'
                ),

                'tokens' => DiscoveryRun::sum(
                    'total_tokens'
                ),
            ],
        ];
    }
};
?>

<div wire:poll.5s class="space-y-6">

    {{-- Success notification --}}
    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    {{-- Page heading --}}
    <div class="flex flex-wrap items-center justify-between gap-4">

        <div>
            <h1 class="text-2xl font-bold text-slate-900">
                Discovery
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                AI-powered article discovery and execution history
            </p>
        </div>

        <a
            href="{{ route('admin.discovery.create') }}"
            class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
        >
            + New Discovery Run
        </a>

    </div>

    {{-- Statistics --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">

        @foreach ([
            ['label' => 'Total Runs', 'value' => $stats['total']],
            ['label' => 'Completed', 'value' => $stats['completed']],
            ['label' => 'Failed', 'value' => $stats['failed']],
            ['label' => 'Accepted Articles', 'value' => $stats['articles']],
            ['label' => 'Google Search Calls', 'value' => $stats['searches']],
            ['label' => 'Total Tokens', 'value' => $stats['tokens']],
        ] as $stat)

            <div
                class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm"
            >
                <div class="text-sm font-medium text-slate-500">
                    {{ $stat['label'] }}
                </div>

                <div class="mt-2 text-2xl font-bold text-slate-900">
                    {{ number_format($stat['value'] ?? 0) }}
                </div>
            </div>

        @endforeach

    </div>

    {{-- Discovery runs --}}
    <div class="rounded-xl border border-slate-200 bg-white shadow-sm">

        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 p-5">

            <div>
                <h2 class="text-lg font-semibold text-slate-900">
                    Discovery Runs
                </h2>

                <p class="text-sm text-slate-500">
                    History of article discovery executions
                </p>
            </div>

            <div class="flex flex-wrap gap-3">

                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search source or run ID"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                >

                <select
                    wire:model.live="status"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm"
                >
                    <option value="all">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="running">Running</option>
                    <option value="completed">Completed</option>
                    <option value="failed">Failed</option>
                </select>

            </div>

        </div>

        <div class="overflow-x-auto">

            <table class="min-w-full divide-y divide-slate-200 text-sm">

                <thead class="bg-slate-50">
                <tr class="text-left text-xs uppercase tracking-wide text-slate-500">
                    <th class="px-4 py-3">Run</th>
                    <th class="px-4 py-3">Source</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">GEO</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Found</th>
                    <th class="px-4 py-3">Accepted</th>
                    <th class="px-4 py-3">Searches</th>
                    <th class="px-4 py-3">Tokens</th>
                    <th class="px-4 py-3">Started</th>
                    <th class="px-4 py-3"></th>
                </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 bg-white">

                @forelse ($runs as $run)

                    <tr
                        wire:key="discovery-run-{{ $run->id }}"
                        class="hover:bg-slate-50"
                    >

                        <td class="px-4 py-3 font-semibold">
                            #{{ $run->id }}
                        </td>

                        <td class="px-4 py-3">
                            {{ $run->source?->name ?? '—' }}
                        </td>

                        <td class="px-4 py-3">
                            {{ $run->category?->name ?? '—' }}
                        </td>

                        <td class="px-4 py-3">
                            {{ $run->geo_type }} #{{ $run->geo_id }}
                        </td>

                        <td class="px-4 py-3">

                            @php
                                $statusClass = match ($run->status) {
                                    'completed' => 'bg-green-100 text-green-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                    'running' => 'bg-blue-100 text-blue-800',
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    default => 'bg-slate-100 text-slate-700',
                                };
                            @endphp

                            <span
                                class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}"
                            >
                                    {{ ucfirst($run->status) }}
                                </span>

                        </td>

                        <td class="px-4 py-3">
                            {{ $run->found_count ?? 0 }}
                        </td>

                        <td class="px-4 py-3">
                            {{ $run->accepted_count ?? 0 }}
                        </td>

                        <td class="px-4 py-3">
                            {{ $run->google_search_count ?? 0 }}
                        </td>

                        <td class="px-4 py-3">
                            {{ number_format($run->total_tokens ?? 0) }}
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">
                            {{ $run->started_at?->format('Y-m-d H:i') ?? '—' }}
                        </td>

                        <td class="px-4 py-3">
                            <button
                                type="button"
                                wire:click="selectRun({{ $run->id }})"
                                class="font-medium text-indigo-600 hover:text-indigo-800"
                            >
                                Details
                            </button>
                        </td>

                    </tr>

                @empty

                    <tr>
                        <td
                            colspan="11"
                            class="px-4 py-12 text-center text-slate-500"
                        >
                            No Discovery Runs found.
                        </td>
                    </tr>

                @endforelse

                </tbody>

            </table>

        </div>

        <div class="border-t border-slate-200 p-4">
            {{ $runs->links() }}
        </div>

    </div>

    {{-- Selected run details --}}
    @if ($selectedRun)

        <div
            wire:key="selected-discovery-run-{{ $selectedRun->id }}"
            class="rounded-xl border border-slate-200 bg-white shadow-sm"
        >

            <div class="flex items-center justify-between border-b border-slate-200 p-5">

                <div>
                    <h2 class="text-lg font-semibold text-slate-900">
                        Discovery Run #{{ $selectedRun->id }}
                    </h2>

                    <p class="text-sm text-slate-500">
                        {{ $selectedRun->source?->name ?? 'Unknown source' }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="closeDetails"
                    class="rounded-lg border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50"
                >
                    Close
                </button>

            </div>

            <div class="grid grid-cols-2 gap-4 p-5 text-sm md:grid-cols-4">

                <div>
                    <div class="text-slate-500">Status</div>
                    <div class="font-semibold">
                        {{ ucfirst($selectedRun->status) }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Google Searches</div>
                    <div class="font-semibold">
                        {{ $selectedRun->google_search_count ?? 0 }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Input Tokens</div>
                    <div class="font-semibold">
                        {{ number_format($selectedRun->input_tokens ?? 0) }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Output Tokens</div>
                    <div class="font-semibold">
                        {{ number_format($selectedRun->output_tokens ?? 0) }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Found</div>
                    <div class="font-semibold">
                        {{ $selectedRun->found_count ?? 0 }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Accepted</div>
                    <div class="font-semibold">
                        {{ $selectedRun->accepted_count ?? 0 }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Rejected</div>
                    <div class="font-semibold">
                        {{ $selectedRun->rejected_count ?? 0 }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Total Tokens</div>
                    <div class="font-semibold">
                        {{ number_format($selectedRun->total_tokens ?? 0) }}
                    </div>
                </div>

            </div>

            <div class="border-t border-slate-200 p-5">

                <h3 class="mb-4 font-semibold text-slate-900">
                    Discovered Articles
                    ({{ $selectedRun->sourceArticles->count() }})
                </h3>

                <div class="space-y-3">

                    @forelse ($selectedRun->sourceArticles as $article)

                        <div
                            wire:key="article-{{ $article->id }}"
                            class="rounded-lg border border-slate-200 p-4"
                        >

                            <a
                                href="{{ $article->url }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-semibold text-indigo-600 hover:underline"
                            >
                                {{ $article->title }}
                            </a>

                            <div class="mt-1 text-xs text-slate-500">
                                {{ $article->published_at }}
                            </div>

                            @if ($article->summary)
                                <p class="mt-2 text-sm text-slate-600">
                                    {{ $article->summary }}
                                </p>
                            @endif

                            <div class="mt-2 break-all text-xs text-slate-400">
                                {{ $article->url }}
                            </div>

                        </div>

                    @empty

                        <p class="text-sm text-slate-500">
                            No articles associated with this run.
                        </p>

                    @endforelse

                </div>

            </div>

            @if ($selectedRun->error_message)

                <div class="border-t border-red-100 bg-red-50 p-5">

                    <h3 class="font-semibold text-red-800">
                        Execution Error
                    </h3>

                    <p class="mt-2 whitespace-pre-wrap break-words text-sm text-red-700">
                        {{ $selectedRun->error_message }}
                    </p>

                </div>

            @endif

        </div>

    @endif

</div>
