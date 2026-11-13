<?php

use App\Models\Event;
use Livewire\Component;

new class extends Component
{
    public Event $event;

    public function mount(Event $event): void
    {
        $this->event = $event->load([
            'category',
            'country',
            'sourceArticles',
            'markets',
        ]);
    }
};
?>

<div>
    <div class="mb-8 flex items-start justify-between gap-4">
        <div>
            <a
                href="{{ route('admin.events.index') }}"
                class="text-sm text-gray-500 hover:text-gray-900"
            >
                ← Back to Events
            </a>

            <h1 class="mt-3 text-3xl font-bold">
                {{ $event->title }}
            </h1>

            <p class="mt-2 text-sm text-gray-500">
                Event #{{ $event->id }}
            </p>
        </div>

        <div class="flex flex-wrap gap-3">
            <a
                href="{{ route('admin.events.edit', $event) }}"
                class="rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium hover:bg-gray-50"
            >
                Edit Event
            </a>

            <a
                href="{{ route('admin.events.markets.create', $event) }}"
                class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800"
            >
                Create Market
            </a>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold">
                    Event Description
                </h2>

                <p class="whitespace-pre-line text-sm leading-7 text-gray-700">
                    {{ $event->description ?: 'No description available.' }}
                </p>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold">
                        Source Articles
                    </h2>

                    <span class="text-sm text-gray-500">
                        {{ $event->sourceArticles->count() }} articles
                    </span>
                </div>

                <div class="divide-y divide-gray-100">
                    @forelse ($event->sourceArticles as $article)
                        <div class="py-4 first:pt-0 last:pb-0">
                            <a
                                href="{{ $article->canonical_url }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-medium text-gray-900 hover:underline"
                            >
                                {{ $article->title }}
                            </a>

                            <div class="mt-2 text-xs text-gray-500">
                                Published:
                                {{ $article->published_at?->format('Y-m-d H:i') ?? 'Unknown' }}
                            </div>

                            @if ($article->summary)
                                <p class="mt-3 text-sm leading-6 text-gray-600">
                                    {{ $article->summary }}
                                </p>
                            @endif
                        </div>
                    @empty
                        <p class="py-6 text-sm text-gray-500">
                            No source articles linked to this event.
                        </p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-xl bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-semibold">
                        Prediction Markets
                    </h2>

                    <span class="text-sm text-gray-500">
                        {{ $event->markets->count() }} markets
                    </span>
                </div>

                <div class="divide-y divide-gray-100">
                    @forelse ($event->markets as $market)
                        <div class="flex items-center justify-between gap-4 py-4 first:pt-0 last:pb-0">
                            <div>
                                <a
                                    href="{{ route('admin.markets.show', $market) }}"
                                    class="font-medium text-gray-900 hover:underline"
                                >
                                    {{ $market->question }}
                                </a>

                                <div class="mt-2 text-xs text-gray-500">
                                    Market #{{ $market->id }}
                                    · {{ ucfirst($market->status) }}
                                </div>
                            </div>

                            <a
                                href="{{ route('admin.markets.edit', $market) }}"
                                class="text-sm text-gray-600 hover:underline"
                            >
                                Edit
                            </a>
                        </div>
                    @empty
                        <p class="py-6 text-sm text-gray-500">
                            No markets created for this event yet.
                        </p>
                    @endforelse
                </div>
            </section>
        </div>

        <div>
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <h2 class="mb-5 text-lg font-semibold">
                    Event Information
                </h2>

                <dl class="space-y-5 text-sm">
                    <div>
                        <dt class="text-gray-500">Status</dt>
                        <dd class="mt-1 font-medium">
                            {{ ucfirst($event->status) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Country</dt>
                        <dd class="mt-1 font-medium">
                            {{ $event->country?->name ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Category</dt>
                        <dd class="mt-1 font-medium">
                            {{ $event->category?->name ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Source Language</dt>
                        <dd class="mt-1 font-medium">
                            {{ strtoupper($event->source_locale ?? '—') }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Event Date</dt>
                        <dd class="mt-1 font-medium">
                            {{ $event->event_at?->format('Y-m-d H:i') ?? 'Unknown' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Created</dt>
                        <dd class="mt-1 font-medium">
                            {{ $event->created_at?->format('Y-m-d H:i') ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">Updated</dt>
                        <dd class="mt-1 font-medium">
                            {{ $event->updated_at?->format('Y-m-d H:i') ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</div>
