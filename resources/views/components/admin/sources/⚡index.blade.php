<?php

use App\Models\Source;
use Livewire\Component;

new class extends Component
{
    public ?int $sourceToDelete = null;

    public ?string $sourceToDeleteName = null;

    public function with(): array
    {
        return [
            'sources' => Source::query()
                ->with([
                    'language',
                    'categories',
                    'countries',
                    'countryGroups',
                ])
                ->orderBy('name')
                ->get(),
        ];
    }

    public function confirmDelete(int $sourceId): void
    {
        $source = Source::query()->findOrFail($sourceId);

        $this->sourceToDelete = $source->id;
        $this->sourceToDeleteName = $source->name;
    }

    public function cancelDelete(): void
    {
        $this->sourceToDelete = null;
        $this->sourceToDeleteName = null;
    }

    public function deleteSource(): void
    {
        if ($this->sourceToDelete === null) {
            return;
        }

        $source = Source::query()->findOrFail(
            $this->sourceToDelete
        );

        $hasHistory = \Illuminate\Support\Facades\DB::table('discovery_runs')
            ->where('source_id', $source->id)
            ->exists();

        if ($hasHistory) {
            $this->cancelDelete();

            session()->flash(
                'error',
                'Cannot delete this source because it has Discovery history.'
            );

            return;
        }

        $name = $source->name;

        $source->categories()->detach();
        $source->countries()->detach();
        $source->countryGroups()->detach();

        $source->delete();

        $this->cancelDelete();

        session()->flash(
            'success',
            "Source '{$name}' was deleted successfully."
        );
    }
};

?>

<div>
    <div class="mb-8 flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold">
                Sources
            </h1>

            <p class="mt-2 text-gray-500">
                Manage content sources used by Discovery.
            </p>
        </div>

        <a
            href="{{ route('admin.sources.create') }}"
            class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-gray-800"
        >
            Add Source
        </a>
    </div>

    @if (session()->has('success'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="border-b border-gray-200 bg-gray-50">
                <tr>
                    <th class="px-6 py-4 text-sm font-medium text-gray-600">
                        Source
                    </th>

                    <th class="px-6 py-4 text-sm font-medium text-gray-600">
                        Language
                    </th>

                    <th class="px-6 py-4 text-sm font-medium text-gray-600">
                        GEO
                    </th>

                    <th class="px-6 py-4 text-sm font-medium text-gray-600">
                        Categories
                    </th>

                    <th class="px-6 py-4 text-sm font-medium text-gray-600">
                        Priority
                    </th>

                    <th class="px-6 py-4 text-right text-sm font-medium text-gray-600">
                        Actions
                    </th>
                </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                @forelse ($sources as $source)
                    <tr wire:key="source-{{ $source->id }}">
                        <td class="px-6 py-4">
                            <a
                                href="{{ route('admin.sources.edit', $source) }}"
                                class="font-medium text-gray-900 hover:underline"
                            >
                                {{ $source->name }}
                            </a>

                            <div class="mt-1 text-sm text-gray-500">
                                {{ $source->domain }}
                            </div>
                        </td>

                        <td class="px-6 py-4 text-sm">
                            {{ $source->language?->name ?? '—' }}
                        </td>

                        <td class="px-6 py-4 text-sm">
                            @php
                                $geo = $source->countries
                                    ->pluck('iso2')
                                    ->merge(
                                        $source->countryGroups->pluck('name')
                                    );
                            @endphp

                            {{ $geo->isNotEmpty() ? $geo->join(', ') : '—' }}
                        </td>

                        <td class="px-6 py-4 text-sm">
                            {{ $source->categories->pluck('name')->join(', ') ?: '—' }}
                        </td>

                        <td class="px-6 py-4 text-sm">
                            {{ $source->priority }}
                        </td>

                        <td class="px-6 py-4">
                            <div class="flex items-center justify-end gap-3">
                                <a
                                    href="{{ route('admin.sources.edit', $source) }}"
                                    class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                >
                                    Edit
                                </a>

                                <button
                                    type="button"
                                    wire:click="confirmDelete({{ $source->id }})"
                                    class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                                >
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td
                            colspan="6"
                            class="px-6 py-12 text-center text-gray-500"
                        >
                            No sources have been added yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($sourceToDelete !== null)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="delete-source-title"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="24"
                        height="24"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                        />
                    </svg>
                </div>

                <h2
                    id="delete-source-title"
                    class="text-xl font-semibold text-gray-900"
                >
                    Delete Source
                </h2>

                <p class="mt-3 text-sm leading-6 text-gray-600">
                    Are you sure you want to delete
                    <strong class="text-gray-900">
                        {{ $sourceToDeleteName }}
                    </strong>?
                </p>

                <p class="mt-2 text-sm text-gray-500">
                    This action cannot be undone. Sources with existing
                    Discovery history cannot be deleted.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        wire:click="cancelDelete"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>

                    <button
                        type="button"
                        wire:click="deleteSource"
                        wire:loading.attr="disabled"
                        wire:target="deleteSource"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="deleteSource">
                            Delete Source
                        </span>

                        <span wire:loading wire:target="deleteSource">
                            Deleting...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
