<?php

use App\Models\Event;
use App\Models\Market;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component
{
    public ?int $event_id = null;

    public string $question = '';
    public string $description = '';
    public string $resolution_rules = '';
    public string $source_locale = 'en';

    public string $finish_at = '';
    public string $resolution_at = '';

    public array $outcomes = [
        ['title' => ''],
        ['title' => ''],
    ];

    public function mount(?Event $event = null): void
    {
        if ($event && $event->exists) {
            $this->event_id = $event->id;
            $this->source_locale = $event->source_locale ?: 'en';
        }
    }

    public function addOutcome(): void
    {
        $this->outcomes[] = ['title' => ''];
    }

    public function removeOutcome(int $index): void
    {
        if (count($this->outcomes) <= 2) {
            return;
        }

        if (!array_key_exists($index, $this->outcomes)) {
            return;
        }

        unset($this->outcomes[$index]);

        $this->outcomes = array_values($this->outcomes);

        $this->resetValidation('outcomes');
    }

    public function save()
    {
        $validated = $this->validate([
            'event_id' => [
                'required',
                'integer',
                Rule::exists('events', 'id'),
            ],

            'question' => [
                'required',
                'string',
                'max:500',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'resolution_rules' => [
                'required',
                'string',
            ],

            'source_locale' => [
                'required',
                'string',
                'max:10',
            ],

            'finish_at' => [
                'required',
                'date',
                'after:now',
            ],

            'resolution_at' => [
                'required',
                'date',
                'after_or_equal:finish_at',
            ],

            'outcomes' => [
                'required',
                'array',
                'min:2',
            ],

            'outcomes.*.title' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $normalizedOutcomes = [];
        $usedTitles = [];
        $usedCodes = [];

        foreach ($validated['outcomes'] as $index => $outcome) {
            $title = trim($outcome['title']);
            $titleKey = mb_strtolower($title);

            if ($title === '') {
                throw ValidationException::withMessages([
                    "outcomes.$index.title" => 'Outcome title cannot be empty.',
                ]);
            }

            if (in_array($titleKey, $usedTitles, true)) {
                throw ValidationException::withMessages([
                    "outcomes.$index.title" => 'Outcome titles must be unique.',
                ]);
            }

            $usedTitles[] = $titleKey;

            $baseCode = Str::slug($title, '_');

            if ($baseCode === '') {
                $baseCode = 'outcome';
            }

            $baseCode = Str::limit($baseCode, 80, '');

            $code = $baseCode;
            $suffix = 2;

            while (in_array($code, $usedCodes, true)) {
                $code = $baseCode . '_' . $suffix;
                $suffix++;
            }

            $usedCodes[] = $code;

            $normalizedOutcomes[] = [
                'code' => $code,
                'title' => $title,
                'sort_order' => $index + 1,
            ];
        }

        $market = DB::transaction(function () use (
            $validated,
            $normalizedOutcomes
        ) {
            $market = Market::create([
                'event_id' => $validated['event_id'],
                'question' => trim($validated['question']),
                'description' => $validated['description'] ?: null,
                'resolution_rules' => trim($validated['resolution_rules']),
                'source_locale' => strtolower(trim($validated['source_locale'])),
                'finish_at' => $validated['finish_at'],
                'resolution_at' => $validated['resolution_at'],
                'status' => 'draft',
            ]);

            $market->outcomes()->createMany($normalizedOutcomes);

            return $market;
        });

        return $this->redirectRoute(
            'admin.markets.show',
            ['market' => $market->id],
            navigate: true
        );
    }

    public function with(): array
    {
        return [
            'events' => Event::query()
                ->orderByDesc('created_at')
                ->get(['id', 'title', 'source_locale']),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <a
            href="{{ $event_id ? route('admin.events.show', $event_id) : route('admin.markets.index') }}"
            class="text-sm text-gray-500 hover:text-gray-900"
        >
            ← Back
        </a>

        <h1 class="mt-3 text-3xl font-bold">
            Create Market
        </h1>

        <p class="mt-2 text-gray-500">
            Create a prediction market with two or more outcomes.
        </p>
    </div>

    <div class="max-w-5xl">
        <form
            wire:submit="save"
            class="space-y-8 rounded-xl bg-white p-6 shadow-sm"
        >

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

            {{-- Related Event --}}
            <section>
                <h2 class="text-lg font-semibold">
                    Related Event
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Select the event this market belongs to.
                </p>

                <select
                    wire:model="event_id"
                    class="mt-4 w-full rounded-lg border border-gray-300 px-3 py-2.5"
                >
                    <option value="">Select event</option>

                    @foreach ($events as $event)
                        <option value="{{ $event->id }}">
                            #{{ $event->id }} — {{ $event->title }}
                        </option>
                    @endforeach
                </select>

                @error('event_id')
                <p class="mt-2 text-sm text-red-600">
                    {{ $message }}
                </p>
                @enderror
            </section>

            {{-- Market Information --}}
            <section>
                <h2 class="text-lg font-semibold">
                    Market Information
                </h2>

                <div class="mt-4 space-y-5">
                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Market Question
                        </label>

                        <input
                            type="text"
                            wire:model="question"
                            placeholder="Who will win the election?"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >

                        @error('question')
                        <p class="mt-2 text-sm text-red-600">
                            {{ $message }}
                        </p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Description
                        </label>

                        <textarea
                            wire:model="description"
                            rows="4"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        ></textarea>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Resolution Rules
                        </label>

                        <textarea
                            wire:model="resolution_rules"
                            rows="7"
                            placeholder="Define the winning conditions, verification sources and cancellation rules..."
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        ></textarea>

                        <p class="mt-2 text-xs text-gray-500">
                            Specify how the winning outcome will be determined,
                            which sources will be used, and when the market
                            should be cancelled.
                        </p>

                        @error('resolution_rules')
                        <p class="mt-2 text-sm text-red-600">
                            {{ $message }}
                        </p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Source Language
                        </label>

                        <input
                            type="text"
                            wire:model="source_locale"
                            maxlength="10"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5 md:w-48"
                        >
                    </div>
                </div>
            </section>

            {{-- Schedule --}}
            <section>
                <h2 class="text-lg font-semibold">
                    Market Schedule
                </h2>

                <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Betting Closes At
                        </label>

                        <input
                            type="datetime-local"
                            wire:model="finish_at"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >

                        @error('finish_at')
                        <p class="mt-2 text-sm text-red-600">
                            {{ $message }}
                        </p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Resolution At
                        </label>

                        <input
                            type="datetime-local"
                            wire:model="resolution_at"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >

                        @error('resolution_at')
                        <p class="mt-2 text-sm text-red-600">
                            {{ $message }}
                        </p>
                        @enderror
                    </div>
                </div>
            </section>

            {{-- Outcomes --}}
            <section>
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">
                            Outcomes
                        </h2>

                        <p class="mt-1 text-sm text-gray-500">
                            Add at least two possible outcomes.
                        </p>
                    </div>

                    <span class="rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700">
                        {{ count($outcomes) }} outcomes
                    </span>
                </div>

                <div class="mt-5 space-y-3">
                    @foreach ($outcomes as $index => $outcome)
                        <div
                            wire:key="outcome-{{ $index }}"
                            class="rounded-lg border border-gray-200 p-4"
                        >
                            <div class="flex items-start gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-sm font-semibold text-gray-600">
                                    {{ $index + 1 }}
                                </div>

                                <div class="min-w-0 flex-1">
                                    <input
                                        type="text"
                                        wire:model="outcomes.{{ $index }}.title"
                                        placeholder="Enter outcome title"
                                        maxlength="255"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                                    >

                                    @error("outcomes.$index.title")
                                    <p class="mt-2 text-sm text-red-600">
                                        {{ $message }}
                                    </p>
                                    @enderror
                                </div>

                                <button
                                    type="button"
                                    wire:click="removeOutcome({{ $index }})"
                                    @disabled(count($outcomes) <= 2)
                                    class="rounded-lg border border-red-200 px-3 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-30"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                @error('outcomes')
                <p class="mt-3 text-sm text-red-600">
                    {{ $message }}
                </p>
                @enderror

                <button
                    type="button"
                    wire:click="addOutcome"
                    class="mt-4 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    + Add Outcome
                </button>

                <p class="mt-3 text-xs text-gray-500">
                    Outcomes will be saved in the order shown above.
                    Each outcome must have a unique title.
                </p>
            </section>

            {{-- Actions --}}
            <div class="flex justify-end gap-3 border-t border-gray-100 pt-6">
                <a
                    href="{{ $event_id ? route('admin.events.show', $event_id) : route('admin.markets.index') }}"
                    class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium hover:bg-gray-50"
                >
                    Cancel
                </a>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="save">
                        Create Market
                    </span>

                    <span wire:loading wire:target="save">
                        Creating...
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>
