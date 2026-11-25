<?php

use App\Models\Source;
use App\Models\SourceArticle;
use App\Models\Event;
use App\Models\Market;
use Livewire\Component;

new class extends Component
{
    public string $title = 'Dashboard';

    public function with(): array
    {
        return [
            'sourcesCount' => Source::query()->count(),
            'articlesCount' => SourceArticle::query()->count(),
            'eventsCount' => Event::query()->count(),
            'marketsCount' => Market::query()->count(),
        ];
    }
};
?>

<div>
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900">
            Dashboard
        </h1>

        <p class="mt-2 text-gray-500">
            Prediction market content management
        </p>
    </div>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">

        <div class="rounded-xl bg-white p-6 shadow-sm">
            <div class="text-sm text-gray-500">
                Sources
            </div>

            <div class="mt-2 text-3xl font-semibold text-gray-900">
                {{ number_format($sourcesCount) }}
            </div>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm">
            <div class="text-sm text-gray-500">
                Discovered Articles
            </div>

            <div class="mt-2 text-3xl font-semibold text-gray-900">
                {{ number_format($articlesCount) }}
            </div>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm">
            <div class="text-sm text-gray-500">
                Events
            </div>

            <div class="mt-2 text-3xl font-semibold text-gray-900">
                {{ number_format($eventsCount) }}
            </div>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm">
            <div class="text-sm text-gray-500">
                Markets
            </div>

            <div class="mt-2 text-3xl font-semibold text-gray-900">
                {{ number_format($marketsCount) }}
            </div>
        </div>

    </div>
</div>
