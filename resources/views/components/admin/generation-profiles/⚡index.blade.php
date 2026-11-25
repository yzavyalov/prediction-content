<?php

use App\Models\GenerationProfile;
use App\Models\GenerationRun;
use App\Services\Generation\GenerationRunService;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public ?string $errorMessage = null;

    public function startGeneration(
        int $profileId,
        GenerationRunService $service
    ): void {
        $this->errorMessage = null;

        $profile = GenerationProfile::query()
            ->findOrFail($profileId);

        if ($profile->status !== 'active') {
            $this->errorMessage =
                'Activate the generation profile before starting.';

            return;
        }

        try {
            $run = $service->create($profile);
            $queued = $service->dispatch($run);

            session()->flash(
                'success',
                "Generation Run #{$run->id} created. {$queued} Discovery Runs queued."
            );

            $this->resetPage('runsPage');
        } catch (\Throwable $e) {
            report($e);

            $this->errorMessage =
                'Could not start generation. Check application logs.';
        }
    }

    public function formatFreshness(int $minutes): string
    {
        if ($minutes >= 1440 && $minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days . ($days === 1 ? ' day' : ' days');
        }

        if ($minutes >= 60 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours . ($hours === 1 ? ' hour' : ' hours');
        }

        return $minutes . ' min';
    }

    public function with(): array
    {
        return [
            'profiles' => GenerationProfile::query()
                ->with([
                    'categories',
                    'countries',
                    'countryGroups',
                    'languages',
                    'sources',
                ])
                ->orderBy('name')
                ->paginate(15, ['*'], 'profilesPage'),

            'generationRuns' => GenerationRun::query()
                ->with('generationProfile')
                ->withCount([
                    'discoveryRuns',

                    'discoveryRuns as completed_discovery_runs_count'
                    => fn ($query) =>
                    $query->where('status', 'completed'),

                    'discoveryRuns as failed_discovery_runs_count'
                    => fn ($query) =>
                    $query->where('status', 'failed'),
                ])
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'runsPage'),
        ];
    }
};
?>

<div class="space-y-6" wire:poll.10s>

    {{-- Page header --}}
    <div class="flex flex-wrap items-center justify-between gap-4">

        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                Generation
            </h1>

            <p class="mt-2 text-gray-500">
                Launch generation and monitor its progress.
            </p>
        </div>

        <a
            href="{{ route('admin.generation-profiles.create') }}"
            wire:navigate
            class="inline-flex items-center gap-2 rounded-lg
                   bg-blue-600 px-5 py-3 text-sm font-semibold
                   text-white shadow-sm transition hover:bg-blue-700"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M12 4v16m8-8H4"
                />
            </svg>

            Create Generation Profile
        </a>

    </div>

    {{-- Success message --}}
    @if (session('success'))
        <div class="rounded-lg border border-green-200
                    bg-green-50 p-4 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    {{-- Error message --}}
    @if ($errorMessage)
        <div class="rounded-lg border border-red-200
                    bg-red-50 p-4 text-sm text-red-800">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- Generation Profiles --}}
    <section class="overflow-hidden rounded-xl
                    bg-white shadow-sm">

        <div class="flex flex-wrap items-center justify-between
                    gap-3 border-b border-gray-200 px-6 py-5">

            <div>
                <h2 class="text-xl font-semibold text-gray-900">
                    Generation Profiles
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Configure sources and launch new generation runs.
                </p>
            </div>

            <span class="rounded-full bg-gray-100
                         px-3 py-1 text-sm text-gray-600">
                {{ $profiles->total() }} profiles
            </span>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-left text-sm">

                <thead class="border-b border-gray-200
                              bg-gray-50 text-gray-600">

                <tr>
                    <th class="px-5 py-4">Profile</th>
                    <th class="px-5 py-4">Categories</th>
                    <th class="px-5 py-4">GEO</th>
                    <th class="px-5 py-4">Languages</th>
                    <th class="px-5 py-4">Sources</th>
                    <th class="px-5 py-4">Freshness</th>
                    <th class="px-5 py-4">Status</th>
                    <th class="px-5 py-4">Actions</th>
                </tr>

                </thead>

                <tbody class="divide-y divide-gray-100">

                @forelse ($profiles as $profile)

                    <tr
                        wire:key="profile-{{ $profile->id }}"
                        class="transition hover:bg-gray-50"
                    >

                        {{-- Profile --}}
                        <td class="px-5 py-4">

                            <div class="font-medium text-gray-900">
                                {{ $profile->name }}
                            </div>

                            <div class="mt-1 text-xs text-gray-400">
                                ID: {{ $profile->id }}
                            </div>

                        </td>

                        {{-- Categories --}}
                        <td class="px-5 py-4 text-gray-700">
                            {{ $profile->categories->pluck('name')->join(', ') ?: '—' }}
                        </td>

                        {{-- GEO --}}
                        <td class="px-5 py-4 text-gray-700">
                            {{
                                $profile->countries
                                    ->pluck('iso2')
                                    ->merge(
                                        $profile->countryGroups->pluck('name')
                                    )
                                    ->join(', ') ?: '—'
                            }}
                        </td>

                        {{-- Languages --}}
                        <td class="px-5 py-4 text-gray-700">
                            {{ $profile->languages->pluck('name')->join(', ') ?: '—' }}
                        </td>

                        {{-- Sources --}}
                        <td class="px-5 py-4 text-gray-700">

                            @if ($profile->sources->isEmpty())
                                <span class="text-gray-500">
                                    All matching
                                </span>
                            @else
                                {{ $profile->sources->pluck('name')->join(', ') }}
                            @endif

                        </td>

                        {{-- Freshness --}}
                        <td class="whitespace-nowrap px-5 py-4">

                            <span class="rounded-md bg-indigo-50
                                         px-2.5 py-1 text-xs
                                         font-medium text-indigo-700">

                                {{ $this->formatFreshness((int) $profile->freshness_minutes) }}

                            </span>

                        </td>

                        {{-- Profile status --}}
                        <td class="whitespace-nowrap px-5 py-4">

                            @php
                                $profileStatusClass = match ($profile->status) {
                                    'active' => 'bg-green-100 text-green-800',
                                    'inactive' => 'bg-gray-100 text-gray-600',
                                    'draft' => 'bg-yellow-100 text-yellow-800',
                                    default => 'bg-gray-100 text-gray-700',
                                };
                            @endphp

                            <span class="rounded-full px-3 py-1
                                         text-xs font-medium
                                         {{ $profileStatusClass }}">

                                {{ ucfirst($profile->status) }}

                            </span>

                        </td>

                        {{-- Actions --}}
                        <td class="px-5 py-4">

                            <div class="flex items-center gap-2">

                                {{-- Edit profile --}}
                                <a
                                    href="{{ route('admin.generation-profiles.edit', ['profile' => $profile->id]) }}"
                                    wire:navigate
                                    class="whitespace-nowrap rounded-lg
                                           border border-gray-300 bg-white
                                           px-3 py-2 text-sm font-medium
                                           text-gray-700 transition
                                           hover:bg-gray-100"
                                >
                                    Edit
                                </a>

                                {{-- Start generation --}}
                                <button
                                    type="button"
                                    wire:click="startGeneration({{ $profile->id }})"
                                    wire:confirm="Start generation for profile {{ $profile->name }}? Discovery and Gemini requests may incur charges."
                                    wire:loading.attr="disabled"
                                    wire:target="startGeneration({{ $profile->id }})"
                                    @disabled($profile->status !== 'active')
                                    class="whitespace-nowrap rounded-lg
                                           bg-blue-600 px-4 py-2
                                           text-sm font-medium text-white
                                           transition hover:bg-blue-700
                                           disabled:cursor-not-allowed
                                           disabled:opacity-40"
                                >

                                    <span
                                        wire:loading.remove
                                        wire:target="startGeneration({{ $profile->id }})"
                                    >
                                        Start
                                    </span>

                                    <span
                                        wire:loading
                                        wire:target="startGeneration({{ $profile->id }})"
                                    >
                                        Starting...
                                    </span>

                                </button>

                            </div>

                        </td>

                    </tr>

                @empty

                    <tr>

                        <td colspan="8"
                            class="px-5 py-12 text-center">

                            <p class="font-medium text-gray-700">
                                No generation profiles found.
                            </p>

                            <a
                                href="{{ route('admin.generation-profiles.create') }}"
                                wire:navigate
                                class="mt-3 inline-block text-sm
                                       font-medium text-blue-600
                                       hover:text-blue-800"
                            >
                                Create your first generation profile
                            </a>

                        </td>

                    </tr>

                @endforelse

                </tbody>

            </table>

        </div>

        @if ($profiles->hasPages())
            <div class="border-t border-gray-200 px-5 py-4">
                {{ $profiles->links() }}
            </div>
        @endif

    </section>

    {{-- Generation Runs --}}
    <section class="overflow-hidden rounded-xl
                    bg-white shadow-sm">

        <div class="flex flex-wrap items-center justify-between
                    gap-3 border-b border-gray-200 px-6 py-5">

            <div>

                <h2 class="text-xl font-semibold text-gray-900">
                    Generation Runs
                </h2>

                <p class="mt-1 text-sm text-gray-500">
                    Statuses refresh automatically every 10 seconds.
                </p>

            </div>

            <span class="rounded-full bg-gray-100
                         px-3 py-1 text-sm text-gray-600">
                {{ $generationRuns->total() }} runs
            </span>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-left text-sm">

                <thead class="border-b border-gray-200
                              bg-gray-50 text-gray-600">

                <tr>
                    <th class="px-5 py-4">Run</th>
                    <th class="px-5 py-4">Profile</th>
                    <th class="px-5 py-4">Discovery</th>
                    <th class="px-5 py-4">Completed</th>
                    <th class="px-5 py-4">Failed</th>
                    <th class="px-5 py-4">Status</th>
                    <th class="px-5 py-4">Created</th>
                </tr>

                </thead>

                <tbody class="divide-y divide-gray-100">

                @forelse ($generationRuns as $run)

                    <tr
                        wire:key="generation-run-{{ $run->id }}"
                        class="transition hover:bg-gray-50"
                    >

                        {{-- Run ID --}}
                        <td class="whitespace-nowrap px-5 py-4
                                   font-semibold text-gray-900">
                            #{{ $run->id }}
                        </td>

                        {{-- Profile --}}
                        <td class="px-5 py-4 text-gray-700">
                            {{ $run->generationProfile?->name ?? '—' }}
                        </td>

                        {{-- Total Discovery Runs --}}
                        <td class="px-5 py-4 font-medium">
                            {{ $run->discovery_runs_count }}
                        </td>

                        {{-- Completed Discovery Runs --}}
                        <td class="px-5 py-4">

                            <span class="font-medium text-green-700">
                                {{ $run->completed_discovery_runs_count }}
                            </span>

                        </td>

                        {{-- Failed Discovery Runs --}}
                        <td class="px-5 py-4">

                            <span class="font-medium
                                {{ $run->failed_discovery_runs_count > 0
                                    ? 'text-red-600'
                                    : 'text-gray-400' }}">

                                {{ $run->failed_discovery_runs_count }}

                            </span>

                        </td>

                        {{-- Generation status --}}
                        <td class="whitespace-nowrap px-5 py-4">

                            @php
                                $statusClass = match ($run->status) {
                                    'completed' => 'bg-green-100 text-green-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                    'running' => 'bg-blue-100 text-blue-800',
                                    'pending' => 'bg-yellow-100 text-yellow-800',
                                    default => 'bg-gray-100 text-gray-700',
                                };
                            @endphp

                            <span class="rounded-full px-3 py-1
                                         text-xs font-medium
                                         {{ $statusClass }}">

                                {{ ucfirst($run->status) }}

                            </span>

                        </td>

                        {{-- Created --}}
                        <td class="whitespace-nowrap px-5 py-4
                                   text-gray-500">

                            {{ $run->created_at?->format('d.m.Y H:i') ?? '—' }}

                        </td>

                    </tr>

                @empty

                    <tr>

                        <td colspan="7"
                            class="px-5 py-12 text-center
                                   text-gray-500">

                            No generation runs yet.

                        </td>

                    </tr>

                @endforelse

                </tbody>

            </table>

        </div>

        @if ($generationRuns->hasPages())

            <div class="border-t border-gray-200 px-5 py-4">
                {{ $generationRuns->links() }}
            </div>

        @endif

    </section>

</div>
