<?php

use App\Jobs\GenerateMarketImage;
use App\Models\Market;
use App\Models\MarketImage;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public Market $market;

    public string $imageNotice = '';

    public bool $showImagePreview = false;

    public function mount(Market $market): void
    {
        $this->market = $market->load([
            'event',
            'outcomes',
            'winningOutcome',
            'generationRun',
        ]);
    }

    public function generateImage(): void
    {
        $createdImageId = DB::transaction(function (): ?int {
            $market = Market::query()
                ->lockForUpdate()
                ->find($this->market->id);

            if (!$market) {
                return null;
            }

            $busy = MarketImage::query()
                ->where('market_id', $market->id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($busy) {
                return null;
            }

            $market->load('translations');

            $english = $market->translations->first(
                function ($translation): bool {
                    $locale = strtolower(
                        str_replace(
                            '_',
                            '-',
                            trim((string) $translation->locale)
                        )
                    );

                    return ($locale === 'en'
                            || str_starts_with($locale, 'en-'))
                        && filled($translation->question);
                }
            );

            $title = trim(
                (string) (
                $english?->question
                    ?: $market->question
                )
            );

            if ($title === '') {
                return null;
            }

            $prompt = 'Создай рекламное изображение с увеличенными логотипами на тему: '
                . $title;

            $image = MarketImage::query()->create([
                'market_id' => $market->id,
                'provider' => 'gemini',
                'model' => config(
                    'services.gemini.models.image',
                    'gemini-2.5-flash-image'
                ),
                'prompt' => $prompt,
                'disk' => 'public',
                'status' => 'pending',
                'attempts' => 0,
                'is_selected' => false,
            ]);

            GenerateMarketImage::dispatch($image->id)
                ->afterCommit();

            return $image->id;
        });

        $this->imageNotice = $createdImageId !== null
            ? 'Image generation queued.'
            : 'Image generation unavailable or already in progress.';
    }

    public function openImagePreview(): void
    {
        $this->showImagePreview = true;
    }

    public function closeImagePreview(): void
    {
        $this->showImagePreview = false;
    }

    public function with(): array
    {
        $selectedImage = MarketImage::query()
            ->where('market_id', $this->market->id)
            ->where('is_selected', true)
            ->where('status', 'completed')
            ->whereNotNull('path')
            ->latest('id')
            ->first();

        $latestImage = MarketImage::query()
            ->where('market_id', $this->market->id)
            ->latest('id')
            ->first();

        return [
            'selectedImage' => $selectedImage,
            'latestImage' => $latestImage,
            'selectedImageUrl' => $selectedImage?->path
                ? asset('storage/' . ltrim($selectedImage->path, '/'))
                : null,
        ];
    }
};
?>

<div wire:poll.5s>
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
        <div class="space-y-6">

            {{-- Market Image --}}
            <section class="rounded-xl bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">
                        Market Image
                    </h2>

                    @if ($latestImage)
                        <span class="rounded-full px-3 py-1 text-xs font-medium
                            {{ $latestImage->status === 'completed'
                                ? 'bg-green-100 text-green-800'
                                : ($latestImage->status === 'failed'
                                    ? 'bg-red-100 text-red-800'
                                    : 'bg-yellow-100 text-yellow-800') }}">
                            {{ ucfirst($latestImage->status) }}
                        </span>
                    @endif
                </div>

                @if ($selectedImageUrl)
                    <button
                        type="button"
                        wire:click="openImagePreview"
                        class="block w-full overflow-hidden rounded-lg border border-gray-200"
                    >
                        <img
                            src="{{ $selectedImageUrl }}"
                            alt="Market image"
                            class="h-auto w-full object-cover"
                        >
                    </button>

                    <p class="mt-2 text-xs text-gray-500">
                        Click image to enlarge.
                    </p>
                @else
                    <div class="flex aspect-video items-center justify-center rounded-lg bg-gray-100 text-sm text-gray-500">
                        No image generated.
                    </div>
                @endif

                @if ($latestImage && in_array($latestImage->status, ['pending', 'processing']))
                    <p class="mt-4 text-sm text-yellow-700">
                        Image generation in progress...
                    </p>
                @endif

                @if ($latestImage?->status === 'failed')
                    <div class="mt-4 rounded-lg bg-red-50 p-3">
                        <p class="text-sm font-medium text-red-800">
                            Image generation failed.
                        </p>

                        @if ($latestImage->error_message)
                            <p class="mt-2 break-words text-xs text-red-700">
                                {{ $latestImage->error_message }}
                            </p>
                        @endif
                    </div>
                @endif

                @if ($imageNotice !== '')
                    <p class="mt-4 text-sm text-gray-600">
                        {{ $imageNotice }}
                    </p>
                @endif

                <button
                    type="button"
                    wire:click="generateImage"
                    wire:loading.attr="disabled"
                    wire:target="generateImage"
                    @disabled($latestImage && in_array($latestImage->status, ['pending', 'processing']))
                    class="mt-5 w-full rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ $selectedImage
                        ? 'Regenerate Image'
                        : 'Generate Image' }}
                </button>
            </section>

            {{-- Market Information --}}
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

    {{-- Image Preview Modal --}}
    @if ($showImagePreview && $selectedImageUrl)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
        >
            <div class="w-full max-w-5xl">
                <div class="mb-3 flex justify-end">
                    <button
                        type="button"
                        wire:click="closeImagePreview"
                        class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-gray-900"
                    >
                        Close
                    </button>
                </div>

                <img
                    src="{{ $selectedImageUrl }}"
                    alt="Market image preview"
                    class="mx-auto max-h-[85vh] w-auto max-w-full rounded-lg object-contain"
                >
            </div>
        </div>
    @endif
</div>
