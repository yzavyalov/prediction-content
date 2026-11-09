<?php

use App\Models\GenerationProfile;
use App\Services\Generation\GenerationRunService;
use Livewire\Component;

new class extends Component
{
    public ?int $selectedProfileId = null;

    public array $preview = [];

    public ?string $errorMessage = null;

    public function previewGeneration(
        int $profileId,
        GenerationRunService $service
    ): void {
        $this->errorMessage = null;

        $profile = GenerationProfile::findOrFail($profileId);

        if ($profile->status !== 'active') {
            $this->errorMessage =
                'Activate the generation profile before starting.';
            return;
        }

        $result = $service->preview($profile);

        $this->selectedProfileId = $profile->id;

        $this->preview = [
            'profile_name' => $profile->name,
            'sources_count' => $result['sources_count'],
            'discovery_runs_count' => $result['discovery_runs_count'],

            'combinations' => $result['combinations']
                ->map(fn (array $item) => [
                    'source' => $item['source']->name,
                    'category' => $item['category']->name,
                    'geo' => $item['geo']->name,
                    'geo_type' => $item['geo'] instanceof \App\Models\Country
                        ? 'Country'
                        : 'Country Group',
                ])
                ->values()
                ->all(),
        ];
    }

    public function cancelGeneration(): void
    {
        $this->selectedProfileId = null;
        $this->preview = [];
        $this->errorMessage = null;
    }

    public function startGeneration(
        GenerationRunService $service
    ): void {
        if ($this->selectedProfileId === null) {
            return;
        }

        $profile = GenerationProfile::findOrFail(
            $this->selectedProfileId
        );

        if ($profile->status !== 'active') {
            $this->errorMessage =
                'Generation profile is not active.';
            return;
        }

        try {
            $run = $service->create($profile);

            session()->flash(
                'success',
                "Generation Run #{$run->id} created. " .
                "{$run->sources_count} sources, " .
                "{$run->discovery_runs_count} Discovery Runs. " .
                "Gemini has not been started."
            );

            $this->cancelGeneration();
        } catch (\Throwable $e) {
            report($e);

            $this->errorMessage =
                'Could not create generation run. Check application logs.';
        }
    }


    public function executeGeneration(
        int $generationRunId,
        GenerationRunService $service
    ): void {
        $this->errorMessage = null;

        try {
            $run = \App\Models\GenerationRun::findOrFail(
                $generationRunId
            );

            $count = $service->dispatch($run);

            session()->flash(
                'success',
                "Generation Run #{$run->id} dispatched. " .
                "{$count} Discovery Runs queued for execution."
            );
        } catch (\Throwable $e) {
            report($e);

            $this->errorMessage =
                'Could not execute generation run. Check application logs.';
        }
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
                ->withCount('generationRuns')
                ->orderBy('name')
                ->get(),

            'generationRuns' => \App\Models\GenerationRun::query()
                ->with('generationProfile')
                ->withCount([
                    'discoveryRuns',
                    'discoveryRuns as pending_discovery_runs_count' => fn ($query) =>
                    $query->where('status', 'pending'),
                    'discoveryRuns as completed_discovery_runs_count' => fn ($query) =>
                    $query->where('status', 'completed'),
                    'discoveryRuns as failed_discovery_runs_count' => fn ($query) =>
                    $query->where('status', 'failed'),
                ])
                ->latest()
                ->limit(20)
                ->get(),
        ];
    }

};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold">
                Generation Profiles
            </h1>

            <p class="mt-2 text-gray-500">
                Configure and launch automatic content generation.
            </p>
        </div>

        <a
            href="{{ route('admin.generation-profiles.create') }}"
            class="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-medium text-white"
        >
            Add Profile
        </a>
    </div>

    @if (session('success'))
        <div class="rounded-lg bg-green-50 p-4 text-green-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($errorMessage)
        <div class="rounded-lg bg-red-50 p-4 text-red-800">
            {{ $errorMessage }}
        </div>
    @endif

    @if ($selectedProfileId !== null)
        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h2 class="text-xl font-semibold">
                Confirm Generation
            </h2>

            <p class="mt-2 text-gray-600">
                {{ $preview['profile_name'] ?? '' }}
            </p>

            <div class="mt-4 grid grid-cols-2 gap-4">
                <div class="rounded-lg bg-gray-50 p-4">
                    <div class="text-sm text-gray-500">
                        Matching sources
                    </div>

                    <div class="mt-1 text-2xl font-bold">
                        {{ $preview['sources_count'] ?? 0 }}
                    </div>
                </div>

                <div class="rounded-lg bg-gray-50 p-4">
                    <div class="text-sm text-gray-500">
                        Planned Discovery Runs
                    </div>

                    <div class="mt-1 text-2xl font-bold">
                        {{ $preview['discovery_runs_count'] ?? 0 }}
                    </div>
                </div>
            </div>


            <div class="mt-5 overflow-x-auto">
                <h3 class="mb-3 text-lg font-semibold">
                    Planned Discovery Runs
                </h3>

                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-gray-50">
                    <tr>
                        <th class="px-4 py-3">Source</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3">GEO</th>
                        <th class="px-4 py-3">GEO Type</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y">
                    @foreach ($preview['combinations'] ?? [] as $combination)
                        <tr>
                            <td class="px-4 py-3">
                                {{ $combination['source'] }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $combination['category'] }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $combination['geo'] }}
                            </td>
                            <td class="px-4 py-3">
                                {{ $combination['geo_type'] }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>


            <p class="mt-4 text-sm text-gray-500">
                Test mode: records will be created, but Gemini
                requests will not be sent.
            </p>

            <div class="mt-5 flex gap-3">
                <button
                    type="button"
                    wire:click="startGeneration"
                    wire:loading.attr="disabled"
                    wire:target="startGeneration"
                    @disabled(($preview['discovery_runs_count'] ?? 0) === 0)
                    class="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    Create Generation Run
                </button>

                <button
                    type="button"
                    wire:click="cancelGeneration"
                    class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium"
                >
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <div class="overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="border-b border-gray-200 bg-gray-50">
                <tr>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Profile</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Categories</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">GEO</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Languages</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Sources</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Freshness</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Target</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Status</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Runs</th>
                    <th class="px-5 py-4 text-sm font-medium text-gray-600">Action</th>
                </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                @forelse ($profiles as $profile)
                    <tr wire:key="profile-{{ $profile->id }}" class="align-top">
                        <td class="px-5 py-4">
                            <a
                                href="{{ route('admin.generation-profiles.edit', $profile) }}"
                                class="font-medium text-gray-900 hover:underline"
                            >
                                {{ $profile->name }}
                            </a>
                        </td>

                        <td class="px-5 py-4 text-sm">
                            {{ $profile->categories->pluck('name')->join(', ') ?: '—' }}
                        </td>

                        <td class="px-5 py-4 text-sm">
                            @php
                                $geo = $profile->countries
                                    ->pluck('iso2')
                                    ->merge($profile->countryGroups->pluck('name'));
                            @endphp

                            {{ $geo->isNotEmpty() ? $geo->join(', ') : '—' }}
                        </td>

                        <td class="px-5 py-4 text-sm">
                            {{ $profile->languages->pluck('name')->join(', ') ?: '—' }}
                        </td>

                        <td class="px-5 py-4 text-sm">
                            {{ $profile->sources->isEmpty()
                                ? 'All matching'
                                : $profile->sources->pluck('name')->join(', ') }}
                        </td>

                        <td class="px-5 py-4 text-sm whitespace-nowrap">
                            {{ $profile->freshness_minutes }}m
                        </td>

                        <td class="px-5 py-4 text-sm">
                            {{ $profile->target_markets_per_run }}
                        </td>

                        <td class="px-5 py-4 text-sm">
                            {{ ucfirst($profile->status) }}
                        </td>

                        <td class="px-5 py-4 text-sm">
                            {{ $profile->generation_runs_count }}
                        </td>

                        <td class="px-5 py-4">
                            <button
                                type="button"
                                wire:click="previewGeneration({{ $profile->id }})"
                                wire:loading.attr="disabled"
                                wire:target="previewGeneration({{ $profile->id }})"
                                @disabled($profile->status !== 'active')
                                class="whitespace-nowrap rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                Start Generation
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-6 py-12 text-center text-gray-500">
                            No generation profiles have been added yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>


    <div class="mt-8 overflow-hidden rounded-xl bg-white shadow-sm">
        <div class="border-b border-gray-200 px-6 py-5">
            <h2 class="text-xl font-semibold">
                Generation Runs
            </h2>

            <p class="mt-1 text-sm text-gray-500">
                Recent generation runs and discovery execution status.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-gray-50">
                <tr>
                    <th class="px-5 py-4">Run</th>
                    <th class="px-5 py-4">Profile</th>
                    <th class="px-5 py-4">Sources</th>
                    <th class="px-5 py-4">Discovery</th>
                    <th class="px-5 py-4">Completed</th>
                    <th class="px-5 py-4">Failed</th>
                    <th class="px-5 py-4">Status</th>
                    <th class="px-5 py-4">Created</th>
                    <th class="px-5 py-4">Action</th>
                </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                @forelse ($generationRuns as $run)
                    <tr wire:key="generation-run-{{ $run->id }}">
                        <td class="px-5 py-4 font-medium">
                            #{{ $run->id }}
                        </td>

                        <td class="px-5 py-4">
                            {{ $run->generationProfile->name }}
                        </td>

                        <td class="px-5 py-4">
                            {{ $run->sources_count }}
                        </td>

                        <td class="px-5 py-4">
                            {{ $run->discovery_runs_count }}
                        </td>

                        <td class="px-5 py-4">
                            {{ $run->completed_discovery_runs_count }}
                        </td>

                        <td class="px-5 py-4">
                            {{ $run->failed_discovery_runs_count }}
                        </td>

                        <td class="px-5 py-4">
                            <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium">
                                {{ ucfirst($run->status) }}
                            </span>
                        </td>

                        <td class="px-5 py-4 whitespace-nowrap text-gray-500">
                            {{ $run->created_at->format('d.m.Y H:i') }}
                        </td>

                        <td class="px-5 py-4">

                            @if ($run->status === 'pending' && $run->pending_discovery_runs_count > 0)
                                <button
                                    type="button"
                                    wire:click="executeGeneration({{ $run->id }})"
                                    wire:confirm="Запустить Generation Run #{{ $run->id }}? В очередь будут отправлены {{ $run->pending_discovery_runs_count }} Discovery Runs. При работающем обработчике очереди начнутся платные запросы к Gemini."
                                    wire:loading.attr="disabled"
                                    wire:target="executeGeneration({{ $run->id }})"
                                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                                >
                                    Execute
                                </button>
                            @elseif ($run->status === 'running')
                                <span class="text-sm text-blue-600">
                                    Running
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif

                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-5 py-10 text-center text-gray-500">
                            No generation runs yet.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
