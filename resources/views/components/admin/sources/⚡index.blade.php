<?php

use App\Models\Source;
use Livewire\Component;

new class extends Component
{
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

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
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
                    Status
                </th>

                <th class="px-6 py-4 text-sm font-medium text-gray-600">
                    Priority
                </th>
            </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">

            @forelse ($sources as $source)
                <tr>
                    <td class="px-6 py-4">
                        <a
                            href="{{ route('admin.sources.edit', $source) }}"
                            class="font-medium hover:underline"
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
                                ->merge($source->countryGroups->pluck('name'));
                        @endphp

                        {{ $geo->isNotEmpty() ? $geo->join(', ') : '—' }}
                    </td>

                    <td class="px-6 py-4 text-sm">
                        {{ $source->categories->pluck('name')->join(', ') ?: '—' }}
                    </td>

                    <td class="px-6 py-4 text-sm">
                        {{ ucfirst($source->status) }}
                    </td>

                    <td class="px-6 py-4 text-sm">
                        {{ $source->priority }}
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
