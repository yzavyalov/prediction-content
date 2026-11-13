<?php

use App\Models\Market;
use Livewire\Component;

new class extends Component
{
    public Market $market;

    public function mount(Market $market): void
    {
        $this->market = $market->load([
            'event',
            'outcomes',
            'winningOutcome',
            'generationRun',
        ]);
    }
};
?>

<div>
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <a
                href="{{ route('admin.markets.index') }}"
                class="text-sm text-gray-500 hover:text-gray-900"
            >
                ← Back to Markets
            </a>

            <h1 class="mt-3 text-3xl font-bold">
                {{ $market->question }}
            </h1>

            <p class="mt-2 text-sm text-gray-500">
                Market #{{ $market->id }}
            </p>
        </div>

        <a
            href="{{ route('admin.markets.edit', $market) }}"
            class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800"
        >
            Edit Market
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        <div class="space-y-6 lg:col-span-2">

            {{-- Description --}}
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">
                    Market Description
                </h2>

                <p class="whitespace-pre-line text-sm leading-7 text-gray-700">
                    {{ $market->description ?: 'No description available.' }}
                </p>
            </section>

            {{-- Outcomes --}}
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <div class="mb-5 flex items-center justify-between">
                    <h2 class="text-lg font-semibold">
                        Outcomes
                    </h2>

                    <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600">
                        {{ $market->outcomes->count() }} outcomes
                    </span>
                </div>

                <div class="space-y-3">
                    @forelse ($market->outcomes as $outcome)
                        <div
                            class="flex items-center justify-between gap-4 rounded-lg border p-4
                                {{ $market->winning_outcome_id === $outcome->id
                                    ? 'border-green-300 bg-green-50'
                                    : 'border-gray-200 bg-gray-50' }}"
                        >
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-sm font-semibold text-gray-600">
                                    {{ $outcome->sort_order }}
                                </div>

                                <div>
                                    <div class="font-medium text-gray-900">
                                        {{ $outcome->title }}
                                    </div>

                                    <div class="mt-1 text-xs text-gray-500">
                                        Code: {{ $outcome->code }}
                                    </div>
                                </div>
                            </div>

                            @if ($market->winning_outcome_id === $outcome->id)
                                <span class="rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-800">
                                    Winner
                                </span>
                            @endif
                        </div>
                    @empty
                        <p class="py-6 text-sm text-gray-500">
                            No outcomes available.
                        </p>
                    @endforelse
                </div>
            </section>

            {{-- Resolution Rules --}}
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">
                    Resolution Rules
                </h2>

                <div class="whitespace-pre-line text-sm leading-7 text-gray-700">
                    {{ $market->resolution_rules ?: 'No resolution rules defined.' }}
                </div>
            </section>

            {{-- Related Event --}}
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">
                    Related Event
                </h2>

                @if ($market->event)
                    <a
                        href="{{ route('admin.events.show', $market->event) }}"
                        class="font-medium text-gray-900 hover:underline"
                    >
                        {{ $market->event->title }}
                    </a>

                    <p class="mt-2 text-xs text-gray-500">
                        Event #{{ $market->event->id }}
                    </p>
                @else
                    <p class="text-sm text-gray-500">
                        No related event.
                    </p>
                @endif
            </section>
        </div>

        {{-- Sidebar --}}
        <div>
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="mb-5 text-lg font-semibold">
                    Market Information
                </h2>

                <dl class="space-y-5 text-sm">
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="mt-1">
                            <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700">
                                {{ ucfirst($market->status) }}
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Source Language</dt>
                        <dd class="mt-1 font-medium">
                            {{ strtoupper($market->source_locale) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Betting Closes</dt>
                        <dd class="mt-1 font-medium">
                            {{ $market->finish_at?->format('Y-m-d H:i') ?? 'Not set' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Resolution Date</dt>
                        <dd class="mt-1 font-medium">
                            {{ $market->resolution_at?->format('Y-m-d H:i') ?? 'Not set' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Resolved At</dt>
                        <dd class="mt-1 font-medium">
                            {{ $market->resolved_at?->format('Y-m-d H:i') ?? 'Not resolved' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Winning Outcome</dt>
                        <dd class="mt-1 font-medium">
                            {{ $market->winningOutcome?->title ?? 'Not selected' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Generation Run</dt>
                        <dd class="mt-1 font-medium">
                            {{ $market->generation_run_id
                                ? '#' . $market->generation_run_id
                                : 'Manual' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Created</dt>
                        <dd class="mt-1 font-medium">
                            {{ $market->created_at?->format('Y-m-d H:i') ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Updated</dt>
                        <dd class="mt-1 font-medium">
                            {{ $market->updated_at?->format('Y-m-d H:i') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</div>
