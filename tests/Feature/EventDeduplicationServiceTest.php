<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Services\AI\GeminiService;
use App\Services\Events\EventDeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EventDeduplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createContext(): array
    {
        $now = now();

        $categoryId = DB::table('categories')->insertGetId([
            'slug' => 'politics',
            'name' => 'Politics',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $countryId = DB::table('countries')->insertGetId([
            'iso2' => 'RO',
            'name' => 'Romania',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$categoryId, $countryId];
    }

    private function eventData(
        string $title = 'Romanian parliamentary confidence vote'
    ): array {
        return [
            'title' => $title,
            'description' => 'Parliament will vote on the proposed cabinet.',
            'event_at' => '2026-12-10T12:00:00+02:00',
        ];
    }

    private function createExistingEvent(
        int $categoryId,
        int $countryId,
        string $title = 'Romanian parliamentary confidence vote',
        ?string $key = null
    ): Event {
        return Event::create([
            'category_id' => $categoryId,
            'country_id' => $countryId,
            'title' => $title,
            'description' => 'A parliamentary confidence vote is scheduled.',
            'status' => 'draft',
            'event_at' => '2026-12-10 10:00:00',
            'source_locale' => 'ro',
            'deduplication_key' => $key,
        ]);
    }

    private function mockGeminiResponse(array $decision): void
    {
        $mock = Mockery::mock(GeminiService::class);

        $mock->shouldReceive('generateText')
            ->once()
            ->withArgs(function ($model, $prompt) {
                return $model === config(
                        'services.gemini.models.deduplication'
                    ) && str_contains($prompt, 'existing_events');
            })
            ->andReturn([
                'outputs' => [
                    [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode($decision),
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'total_input_tokens' => 100,
                    'total_output_tokens' => 30,
                    'total_tokens' => 130,
                ],
            ]);

        $this->app->instance(GeminiService::class, $mock);
    }

    public function test_returns_new_when_no_candidates_exist(): void
    {
        [$categoryId, $countryId] = $this->createContext();

        $result = app(EventDeduplicationService::class)->check(
            $this->eventData(),
            $categoryId,
            $countryId
        );

        $this->assertSame('new', $result['decision']);
        $this->assertNull($result['event_id']);
        $this->assertSame([], $result['usage']);
    }

    public function test_detects_exact_key_without_gemini(): void
    {
        [$categoryId, $countryId] = $this->createContext();

        $data = $this->eventData();

        $key = hash('sha256', json_encode([
            'category_id' => $categoryId,
            'country_id' => $countryId,
            'title' => mb_strtolower($data['title'], 'UTF-8'),
            'event_at' => '2026-12-10T10:00:00+00:00',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $existing = $this->createExistingEvent(
            $categoryId,
            $countryId,
            $data['title'],
            $key
        );

        $result = app(EventDeduplicationService::class)->check(
            $data,
            $categoryId,
            $countryId
        );

        $this->assertSame('existing', $result['decision']);
        $this->assertSame($existing->id, $result['event_id']);
        $this->assertSame([], $result['usage']);
    }

    public function test_gemini_identifies_same_event_with_different_title(): void
    {
        [$categoryId, $countryId] = $this->createContext();

        $existing = $this->createExistingEvent(
            $categoryId,
            $countryId,
            'Parliament to decide on Romania new cabinet'
        );

        $this->mockGeminiResponse([
            'decision' => 'existing',
            'event_id' => $existing->id,
            'reason' => 'Both describe the same confidence vote.',
        ]);

        $result = app(EventDeduplicationService::class)->check(
            $this->eventData(),
            $categoryId,
            $countryId
        );

        $this->assertSame('existing', $result['decision']);
        $this->assertSame($existing->id, $result['event_id']);
        $this->assertSame(130, $result['usage']['total_tokens']);
    }

    public function test_gemini_can_decide_events_are_different(): void
    {
        [$categoryId, $countryId] = $this->createContext();

        $this->createExistingEvent(
            $categoryId,
            $countryId,
            'Separate parliamentary hearing'
        );

        $this->mockGeminiResponse([
            'decision' => 'new',
            'event_id' => null,
            'reason' => 'The events involve different proceedings.',
        ]);

        $result = app(EventDeduplicationService::class)->check(
            $this->eventData(),
            $categoryId,
            $countryId
        );

        $this->assertSame('new', $result['decision']);
        $this->assertNull($result['event_id']);
    }

    public function test_gemini_can_return_uncertain(): void
    {
        [$categoryId, $countryId] = $this->createContext();

        $this->createExistingEvent(
            $categoryId,
            $countryId,
            'Government parliamentary proceedings'
        );

        $this->mockGeminiResponse([
            'decision' => 'uncertain',
            'event_id' => null,
            'reason' => 'Insufficient information to confirm identity.',
        ]);

        $result = app(EventDeduplicationService::class)->check(
            $this->eventData(),
            $categoryId,
            $countryId
        );

        $this->assertSame('uncertain', $result['decision']);
        $this->assertNull($result['event_id']);
    }

    public function test_rejects_unknown_event_id_from_gemini(): void
    {
        [$categoryId, $countryId] = $this->createContext();

        $this->createExistingEvent(
            $categoryId,
            $countryId,
            'Another parliamentary event'
        );

        $this->mockGeminiResponse([
            'decision' => 'existing',
            'event_id' => 999999,
            'reason' => 'Claimed match.',
        ]);

        $this->expectException(RuntimeException::class);

        app(EventDeduplicationService::class)->check(
            $this->eventData(),
            $categoryId,
            $countryId
        );
    }

    public function test_does_not_match_events_from_other_countries(): void
    {
        [$categoryId, $countryId] = $this->createContext();

        $otherCountryId = DB::table('countries')->insertGetId([
            'iso2' => 'BG',
            'name' => 'Bulgaria',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createExistingEvent(
            $categoryId,
            $otherCountryId
        );

        $result = app(EventDeduplicationService::class)->check(
            $this->eventData(),
            $categoryId,
            $countryId
        );

        $this->assertSame('new', $result['decision']);
        $this->assertNull($result['event_id']);
    }

    public function test_does_not_match_events_outside_date_window(): void
    {
        [$categoryId, $countryId] = $this->createContext();

        Event::create([
            'category_id' => $categoryId,
            'country_id' => $countryId,
            'title' => 'Earlier parliamentary vote',
            'description' => 'A different vote.',
            'status' => 'draft',
            'event_at' => '2026-10-01 10:00:00',
            'source_locale' => 'ro',
        ]);

        $result = app(EventDeduplicationService::class)->check(
            $this->eventData(),
            $categoryId,
            $countryId
        );

        $this->assertSame('new', $result['decision']);
    }
}
