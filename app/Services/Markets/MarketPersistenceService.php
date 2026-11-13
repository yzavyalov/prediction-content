<?php

namespace App\Services\Markets;

use App\Models\Event;
use App\Models\Market;
use Illuminate\Support\Facades\DB;

class MarketPersistenceService
{
    public function persist(
        Event $event,
        array $markets,
        ?int $generationRunId = null
    ): array {
        return DB::transaction(function () use (
            $event,
            $markets,
            $generationRunId
        ) {
            $created = 0;
            $existing = 0;

            foreach ($markets as $data) {
                $key = hash('sha256', json_encode([
                    'event_id' => $event->id,
                    'question' => mb_strtolower(trim($data['question'])),
                    'outcomes' => array_map(
                        fn (array $outcome) => [
                            'code' => $outcome['code'],
                            'title' => mb_strtolower(trim($outcome['title'])),
                        ],
                        $data['outcomes']
                    ),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                $duplicate = Market::query()
                    ->where('deduplication_key', $key)
                    ->first();

                if ($duplicate !== null) {
                    $existing++;
                    continue;
                }

                $market = Market::create([
                    'event_id' => $event->id,
                    'generation_run_id' => $generationRunId,
                    'question' => $data['question'],
                    'description' => $data['description'],
                    'status' => 'draft',
                    'source_locale' => $event->source_locale,
                    'finish_at' => $data['finish_at'],
                    'resolution_at' => $data['resolution_at'],
                    'resolution_rules' => $data['resolution_rules'],
                    'deduplication_key' => $key,

                    'monitoring_enabled' => true,
                    'monitoring_status' => 'pending',
                    'last_checked_at' => null,
                    'next_check_at' => now('UTC'),
                    'monitoring_attempts' => 0,
                ]);

                $outcomes = [];

                foreach ($data['outcomes'] as $index => $outcomeData) {
                    $outcome = $market->outcomes()->create([
                        'code' => $outcomeData['code'],
                        'title' => $outcomeData['title'],
                        'sort_order' => $index,
                    ]);

                    $outcomes[$outcomeData['code']] = $outcome;
                }

                foreach ($data['translations'] as $translationData) {
                    $market->translations()->create([
                        'locale' => strtolower($translationData['locale']),
                        'question' => $translationData['question'],
                        'description' => $translationData['description'],
                        'resolution_rules' => $translationData['resolution_rules'],
                    ]);

                    foreach ($translationData['outcomes'] as $outcomeTranslation) {
                        $outcomes[$outcomeTranslation['code']]
                            ->translations()
                            ->create([
                                'locale' => strtolower($translationData['locale']),
                                'title' => $outcomeTranslation['title'],
                            ]);
                    }
                }

                $created++;
            }

            return [
                'created_count' => $created,
                'existing_count' => $existing,
            ];
        });
    }
}
