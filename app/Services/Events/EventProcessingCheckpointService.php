<?php

namespace App\Services\Events;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class EventProcessingCheckpointService
{
    private const DIRECTORY = 'event-processing-checkpoints';

    private const VERSION = 1;

    public function makeKey(
        string $model,
        string $prompt
    ): string {
        return hash(
            'sha256',
            json_encode(
                [
                    'version' => self::VERSION,
                    'model' => $model,
                    'prompt' => $prompt,
                ],
                JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            )
        );
    }

    public function load(
        int $runId,
        string $stage,
        string $key
    ): ?array {
        $path = $this->makePath(
            $runId,
            $stage,
            $key
        );

        $disk = Storage::disk('local');

        if (!$disk->exists($path)) {
            return null;
        }

        try {
            $content = $disk->get($path);

            if (!is_string($content)) {
                throw new RuntimeException(
                    'Unable to read checkpoint.'
                );
            }

            $data = json_decode(
                $content,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                !is_array($data)
                || ($data['version'] ?? null) !== self::VERSION
                || ($data['run_id'] ?? null) !== $runId
                || ($data['stage'] ?? null) !== $stage
                || ($data['key'] ?? null) !== $key
                || !is_array($data['result'] ?? null)
            ) {
                throw new RuntimeException(
                    'Invalid checkpoint structure.'
                );
            }

            Log::info(
                'Event processing checkpoint restored',
                [
                    'discovery_run_id' => $runId,
                    'stage' => $stage,
                    'key' => $key,
                ]
            );

            return $data['result'];

        } catch (Throwable $e) {
            Log::warning(
                'Unable to restore event processing checkpoint',
                [
                    'discovery_run_id' => $runId,
                    'stage' => $stage,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]
            );

            return null;
        }
    }

    public function save(
        int $runId,
        string $stage,
        string $key,
        array $result
    ): void {
        $path = $this->makePath(
            $runId,
            $stage,
            $key
        );

        $disk = Storage::disk('local');

        $content = json_encode(
            [
                'version' => self::VERSION,
                'run_id' => $runId,
                'stage' => $stage,
                'key' => $key,
                'saved_at' => now()->toIso8601String(),
                'result' => $result,
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        );

        $temporaryPath = $path
            . '.'
            . bin2hex(random_bytes(8))
            . '.tmp';

        try {
            if (!$disk->put(
                $temporaryPath,
                $content
            )) {
                throw new RuntimeException(
                    'Unable to write temporary checkpoint.'
                );
            }

            if (!$disk->move(
                $temporaryPath,
                $path
            )) {
                throw new RuntimeException(
                    'Unable to finalize checkpoint.'
                );
            }

            Log::info(
                'Event processing checkpoint saved',
                [
                    'discovery_run_id' => $runId,
                    'stage' => $stage,
                    'key' => $key,
                    'size_bytes' => strlen($content),
                ]
            );

        } catch (Throwable $e) {
            Log::error(
                'Unable to save event processing checkpoint',
                [
                    'discovery_run_id' => $runId,
                    'stage' => $stage,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]
            );

            throw $e;

        } finally {
            if ($disk->exists($temporaryPath)) {
                $disk->delete($temporaryPath);
            }
        }
    }

    private function makePath(
        int $runId,
        string $stage,
        string $key
    ): string {
        if ($runId <= 0) {
            throw new InvalidArgumentException(
                'Invalid Discovery Run ID.'
            );
        }

        if (!in_array(
            $stage,
            ['extraction', 'deduplication'],
            true
        )) {
            throw new InvalidArgumentException(
                'Invalid event processing stage.'
            );
        }

        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new InvalidArgumentException(
                'Invalid checkpoint key.'
            );
        }

        return self::DIRECTORY
            . '/'
            . $runId
            . '/'
            . $stage
            . '/'
            . $key
            . '.json';
    }
}
