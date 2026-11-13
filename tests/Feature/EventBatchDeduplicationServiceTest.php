<?php

namespace Tests\Feature;

use App\Services\AI\GeminiService;
use App\Services\Events\EventDeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EventBatchDeduplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function eventData(
        string $title = 'Romanian parliamentary confidence vote'
    ): array {
        return [
            'title' => $title,
            'description' => 'Parliament will vote on the proposed cabinet.',
            'event_at' => '2026-12-10T10:00:00+00:00',
        ];
    }

    private function candidates(): array
    {
        return [
            $this->eventData(
                'Parliament to vote on Romania new cabinet'
            ),
            $this->eventData(
                'Romanian presidential election'
            ),
        ];
    }

    private function mockGeminiResponse(array $responseData): void
    {
        $gemini = Mockery::mock(GeminiService::class);

        $gemini->shouldReceive('generateText')
            ->once()
            ->withArgs(function ($model, $prompt) {
                return $model === config(
                        'services.gemini.models.deduplication'
                    )
                    && str_contains($prompt, 'batch_0')
                    && str_contains($prompt, 'batch_1');
            })
            ->andReturn([
                'outputs' => [
                    [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode(
                                    $responseData,
                                    JSON_THROW_ON_ERROR
                                ),
                            ],
                        ],
                    ],
                ],
                'usage' => [
                    'total_tokens' => 120,
                ],
            ]);

        $this->app->instance(GeminiService::class, $gemini);
    }

    public function test_returns_new_without_candidates(): void
    {
        $gemini = Mockery::mock(GeminiService::class);
        $gemini->shouldNotReceive('generateText');

        $this->app->instance(GeminiService::class, $gemini);

        $result = app(EventDeduplicationService::class)->checkBatch(
            $this->eventData(),
            []
        );

        $this->assertSame('new', $result['decision']);
        $this->assertNull($result['event_id']);
        $this->assertSame([], $result['usage']);
    }

    public function test_returns_existing_batch_candidate(): void
    {
        $this->mockGeminiResponse([
            'decision' => 'existing',
            'event_id' => 'batch_0',
            'reason' => 'Both descriptions refer to the same vote.',
        ]);

        $result = app(EventDeduplicationService::class)->checkBatch(
            $this->eventData(),
            $this->candidates()
        );

        $this->assertSame('existing', $result['decision']);
        $this->assertSame('batch_0', $result['event_id']);
        $this->assertSame(120, $result['usage']['total_tokens']);
    }

    public function test_returns_new_for_different_event(): void
    {
        $this->mockGeminiResponse([
            'decision' => 'new',
            'event_id' => null,
            'reason' => 'The events are separate occurrences.',
        ]);

        $result = app(EventDeduplicationService::class)->checkBatch(
            $this->eventData(),
            $this->candidates()
        );

        $this->assertSame('new', $result['decision']);
        $this->assertNull($result['event_id']);
    }

    public function test_returns_uncertain_when_identity_is_unclear(): void
    {
        $this->mockGeminiResponse([
            'decision' => 'uncertain',
            'event_id' => null,
            'reason' => 'The available descriptions are insufficient.',
        ]);

        $result = app(EventDeduplicationService::class)->checkBatch(
            $this->eventData(),
            $this->candidates()
        );

        $this->assertSame('uncertain', $result['decision']);
        $this->assertNull($result['event_id']);
    }

    public function test_rejects_unknown_batch_identifier(): void
    {
        $this->mockGeminiResponse([
            'decision' => 'existing',
            'event_id' => 'batch_999',
            'reason' => 'Claimed duplicate.',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Gemini referenced an unknown batch event ID.'
        );

        app(EventDeduplicationService::class)->checkBatch(
            $this->eventData(),
            $this->candidates()
        );
    }

    public function test_rejects_numeric_database_identifier(): void
    {
        $this->mockGeminiResponse([
            'decision' => 'existing',
            'event_id' => 1,
            'reason' => 'Claimed duplicate.',
        ]);

        $this->expectException(RuntimeException::class);

        app(EventDeduplicationService::class)->checkBatch(
            $this->eventData(),
            $this->candidates()
        );
    }

    public function test_rejects_identifier_for_new_decision(): void
    {
        $this->mockGeminiResponse([
            'decision' => 'new',
            'event_id' => 'batch_0',
            'reason' => 'Claimed new event.',
        ]);

        $this->expectException(RuntimeException::class);

        app(EventDeduplicationService::class)->checkBatch(
            $this->eventData(),
            $this->candidates()
        );
    }
}
