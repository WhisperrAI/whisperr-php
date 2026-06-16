<?php

declare(strict_types=1);

namespace Whisperr;

use Whisperr\Contracts\TransportInterface;

/**
 * HTTP transport for the Whisperr ingestion API (cURL, no Composer deps).
 *
 * Delivery outcome mirrors the other Whisperr SDKs:
 *   "ok"    — delivered
 *   "retry" — transient (429, 5xx, network/timeout)
 *   "auth"  — key rejected (401/403); stop and surface
 *   "drop"  — other 4xx (malformed); discard to avoid an infinite retry loop
 */
class Transport implements TransportInterface
{
    /** @param callable(string):void $warn */
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private float $timeout,
        private $warn,
    ) {
    }

    /** @param array<int,array<string,mixed>> $events */
    public function sendBatch(array $events): string
    {
        $payload = ['events' => array_map(static function (array $e): array {
            return [
                'external_user_id' => $e['external_user_id'],
                'event_type' => $e['event_type'],
                'occurred_at' => $e['occurred_at'],
                // cast to object so an empty map serializes as {} not []
                'properties' => (object) ($e['properties'] ?? []),
                // $message_id is an idempotency key for backend dedup, nested in
                // the free-form context so the strict ingestion accepts it.
                'context' => array_merge($e['context'] ?? [], ['$message_id' => $e['message_id']]),
            ];
        }, $events)];

        if (empty($payload['events'])) {
            return 'ok';
        }
        return $this->post('/v1/events/batch', $payload);
    }

    /** @param array<string,mixed> $op */
    public function sendIdentify(array $op): string
    {
        $body = ['external_user_id' => $op['external_user_id']];
        if (!empty($op['traits'])) {
            $body['traits'] = (object) $op['traits'];
        }
        if (!empty($op['preferred_channel'])) {
            $body['preferred_channel'] = $op['preferred_channel'];
        }
        if (!empty($op['channels'])) {
            $body['channels'] = array_map(static function (array $c): array {
                $out = [
                    'channel' => $c['type'] ?? $c['channel'],
                    'address' => $c['address'],
                    'opted_in' => $c['opted_in'] ?? true,
                ];
                if (array_key_exists('verified', $c) && $c['verified'] !== null) {
                    $out['verified'] = $c['verified'];
                }
                return $out;
            }, $op['channels']);
        }
        return $this->post('/v1/identify', $body);
    }

    /** @param array<string,mixed> $body */
    protected function post(string $path, array $body): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->timeout),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return 'retry'; // network error / timeout
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status === 401 || $status === 403) {
            ($this->warn)("auth rejected ($status) — check your Whisperr API key");
            return 'auth';
        }
        if ($status === 429 || $status >= 500) {
            return 'retry';
        }
        ($this->warn)("request to $path dropped ($status)");
        return 'drop';
    }
}
