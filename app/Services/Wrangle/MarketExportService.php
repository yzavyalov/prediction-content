<?php

namespace App\Services\Wrangle;

use App\Models\Market;
use App\Models\MarketExport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MarketExportService
{
    public function __construct(
        private readonly MarketPayloadBuilder $payloadBuilder
    ) {
    }

    public function send(Market $market): MarketExport
    {
        $export = MarketExport::firstOrCreate(
            ['market_id' => $market->id],
            [
                'destination' => 'wrangle',
                'status' => 'pending',
            ]
        );

        // Never resend a successfully exported market.
        if ($export->isCompleted()) {
            return $export;
        }

        // Prevent simultaneous requests for the same market.
        $claimed = MarketExport::query()
            ->whereKey($export->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'processing',
                'error_message' => null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return $export->fresh();
        }

        try {
            $payload = $this->payloadBuilder->build($market);

            $url = rtrim(
                (string) config('services.wrangle.url'),
                '/'
            );

            $secret = (string) config(
                'services.wrangle.import_secret'
            );

            if ($url === '' || $secret === '') {
                throw new RuntimeException(
                    'Wrangle API configuration is missing.'
                );
            }

            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );

            $timestamp = (string) now()->timestamp;
            $requestId = (string) Str::uuid();

            $signature = hash_hmac(
                'sha256',
                $timestamp . "\n" . $requestId . "\n" . $json,
                $secret
            );

            $export->increment('attempts');

            $export->update([
                'last_attempted_at' => now(),
            ]);

            $response = Http::timeout(60)
                ->connectTimeout(10)
                ->withHeaders([
                    'X-Content-Timestamp' => $timestamp,
                    'X-Content-Request-Id' => $requestId,
                    'X-Content-Signature' => $signature,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->withBody($json, 'application/json')
                ->post($url . '/api/content/markets');

            $httpStatus = $response->status();
            $responseData = $response->json();

            if (
                in_array($httpStatus, [200, 201], true) &&
                is_array($responseData) &&
                isset($responseData['bet_id']) &&
                is_numeric($responseData['bet_id']) &&
                (int) $responseData['bet_id'] > 0 &&
                isset($responseData['source_market_id']) &&
                (int) $responseData['source_market_id'] === $market->id
            ) {
                $export->update([
                    'status' => 'completed',
                    'remote_bet_id' => (int) $responseData['bet_id'],
                    'http_status' => $httpStatus,
                    'error_message' => null,
                    'sent_at' => now(),
                ]);

                return $export->fresh();
            }

            $error = is_array($responseData)
                ? json_encode(
                    $responseData,
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
                : 'Invalid API response';

            throw new RuntimeException(
                'Wrangle API HTTP ' . $httpStatus . ': ' .
                mb_substr((string) $error, 0, 2000)
            );
        } catch (Throwable $exception) {
            $export->update([
                'status' => 'failed',
                'error_message' => mb_substr(
                    $exception->getMessage(),
                    0,
                    5000
                ),
            ]);

            return $export->fresh();
        }
    }
}
