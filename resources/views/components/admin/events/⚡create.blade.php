<?php

use App\Models\Category;
use App\Models\Country;
use App\Models\Event;
use Livewire\Component;
use Illuminate\Validation\Rule;

new class extends Component
{
    public string $title = '';
    public string $description = '';
    public string $status = 'draft';
    public string $source_locale = 'en';
    public string $event_at = '';

    public ?int $category_id = null;
    public ?int $country_id = null;

    public function save()
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'status' => [
                'required',
                Rule::in(['draft', 'active', 'completed', 'cancelled', 'rejected']),
            ],
            'source_locale' => ['required', 'string', 'max:10'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'event_at' => ['required', 'date', 'after:now'],
        ]);

        $event = Event::create([
            'title' => trim($validated['title']),
            'description' => $validated['description'] ?: null,
            'status' => $validated['status'],
            'source_locale' => strtolower(trim($validated['source_locale'])),
            'category_id' => $validated['category_id'],
            'country_id' => $validated['country_id'],
            'event_at' => $validated['event_at'] ?: null,
        ]);

        return $this->redirectRoute(
            'admin.events.show',
            ['event' => $event->id],
            navigate: true
        );
    }

    public function with(): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(),
            'countries' => Country::query()->orderBy('name')->get(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <a
            href="{{ route('admin.events.index') }}"
            class="text-sm text-gray-500 hover:text-gray-900"
        >
            ← Back to Events
        </a>

        <h1 class="mt-3 text-3xl font-bold">
            Add Event
        </h1>

        <p class="mt-2 text-gray-500">
            Create a new event manually.
        </p>
    </div>

    <div class="max-w-5xl">
        <form wire:submit="save" class="space-y-8 rounded-xl bg-white p-6 shadow-sm">

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

            <div>
                <h2 class="text-lg font-semibold">Basic Information</h2>

                <div class="mt-4 space-y-5">
                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Event Title
                        </label>
                        <input
                            type="text"
                            wire:model="title"
                            required
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Description
                        </label>
                        <textarea
                            wire:model="description"
                            rows="6"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        ></textarea>
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold">Classification</h2>

                <div class="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Category
                        </label>
                        <select
                            wire:model="category_id"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                            <option value="">Select category</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Country / GEO
                        </label>
                        <select
                            wire:model="country_id"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2.5"
                        >
                            <option value="">Select country</option>
                            @foreach ($countries as $country)
                                <option value="{{ $country->id }}">
                                    {{ $country->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            Source Language
                        </label>
                        <input
                            type="text"
                            wire:model="source_locale"
                            maxlength="10"
                            required
                            placeholder="ro, en, de..."
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
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                </div>
            </div>

            <div>
                <h2 class="text-lg font-semibold">Schedule</h2>

                <div class="mt-4">
                    <label class="mb-2 block text-sm font-medium">
                        Event Date and Time
                    </label>
                    <input
                        type="datetime-local"
                        wire:model="event_at"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2.5 md:w-96"
                    >
                    <p class="mt-2 text-xs text-gray-500">
                        Leave empty if the event date is unknown.
                    </p>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-gray-100 pt-6">
                <a
                    href="{{ route('admin.events.index') }}"
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
                        Create Event
                    </span>
                    <span wire:loading wire:target="save">
                        Creating...
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>
