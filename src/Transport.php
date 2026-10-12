<?php

declare(strict_types=1);

namespace Whisperr;

use Whisperr\Contracts\TransportInterface;
use Whisperr\Contracts\PublisherTransportInterface;

/**
 * HTTP transport for the Whisperr ingestion API (cURL, no Composer deps).
 *
 * Delivery outcome mirrors the other Whisperr SDKs:
 *   "ok"    — delivered
 *   "retry" — transient (429, 5xx, network/timeout)
 *   "auth"  — key rejected (401/403); stop and surface
 *   "drop"  — other 4xx (malformed); discard to avoid an infinite retry loop
 */
class Transport implements TransportInterface, PublisherTransportInterface
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
        $payload = ['events' => array_map([$this, 'eventBody'], $events)];

        if (empty($payload['events'])) {
            return 'ok';
        }
        return $this->post('/v1/events/batch', $payload);
    }

    /** @param array<string,mixed> $op */
    public function sendIdentify(array $op): string
    {
        return $this->post('/v1/identify', $this->identifyBody($op));
    }

    private function identifyBody(array $op): array
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
        return $body;
    }

    /** @param array<string,mixed> $event */
    private function eventBody(array $event): array
    {
        return [
            'external_user_id' => $event['external_user_id'],
            'event_type' => $event['event_type'],
            'occurred_at' => $event['occurred_at'],
            'properties' => (object) ($event['properties'] ?? []),
            // The explicit replay ID always wins over untrusted context fields.
            'context' => array_merge($event['context'] ?? [], ['$message_id' => $event['message_id']]),
        ];
    }

    /** @param array<string,mixed> $event */
    public function publishEvent(array $event): PublishResult
    {
        try {
            $response = $this->request('POST', '/v1/events/track', self::encode($this->eventBody($event)));
        } catch (\JsonException $e) {
            return new PublishResult(false, false, null, null, null, false, 'invalid_payload');
        }
        $status = $response['status'];
        $body = json_decode($response['body'], true);
        if ($status === null || $status === 429 || $status >= 500) {
            return new PublishResult(false, true, $status, null, null, false, 'temporarily_unavailable');
        }
        if ($status < 200 || $status >= 300) {
            return new PublishResult(false, false, $status, null, null, false, $this->errorCode($body, 'request_rejected'));
        }
        $receipt = is_array($body) ? ($body['delivery'] ?? null) : null;
        if (!is_array($receipt) || ($body['processing'] ?? null) !== 'durably_accepted'
            || !is_string($receipt['delivery_id'] ?? null) || trim($receipt['delivery_id']) === ''
            || !is_string($receipt['disposition'] ?? null) || !is_bool($receipt['duplicate'] ?? null)) {
            // Could be a lost/malformed response, or an unbound legacy key.
            // Never turn a successful HTTP status into a false durable receipt.
            return new PublishResult(false, true, $status, null, null, false, 'invalid_receipt');
        }
        $accepted = in_array($receipt['disposition'], ['retrying', 'completed'], true);
        $blocked = in_array($receipt['disposition'], ['quarantined', 'dead_lettered', 'suppressed'], true);
        return new PublishResult($accepted, !$accepted && !$blocked, $status,
            $receipt['delivery_id'], $receipt['disposition'], $receipt['duplicate'],
            $accepted ? null : ($blocked ? 'delivery_' . $receipt['disposition'] : 'invalid_receipt'));
    }

    /** @param mixed $body */
    private function errorCode($body, string $fallback): string
    {
        $code = is_array($body) ? ($body['error']['code'] ?? null) : null;
        return is_string($code) && preg_match('/^[a-z0-9_]{1,100}$/D', $code) ? $code : $fallback;
    }

    /** @param array<string,mixed> $body */
    protected function post(string $path, array $body): string
    {
        try {
            $response = $this->request('POST', $path, self::encode($body));
        } catch (\JsonException $e) {
            // e.g. invalid UTF-8 in properties: unsendable forever, and flush()
            // runs at shutdown, so never let it throw into the host.
            ($this->warn)("request to $path dropped (unencodable JSON: {$e->getMessage()})");
            return 'drop';
        }
        $status = $response['status'];
        if ($status === null) {
            return 'retry';
        }
        if ($status >= 200 && $status < 300) {
            // Spec delivery contract: a 2xx means the server owns the batch.
            // `rejected` counts events that failed shape validation; resending
            // them can never succeed, so they are reported, not retried.
            $ack = $path === '/v1/events/batch' ? json_decode($response['body'], true) : null;
            if (is_array($ack) && is_int($ack['rejected'] ?? null) && $ack['rejected'] > 0) {
                ($this->warn)("server rejected {$ack['rejected']} event(s) in the batch as invalid");
            }
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

    /**
     * @param array<string,mixed> $body
     * @throws \JsonException
     */
    private static function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * One attempt, with redirects disabled so credentials never follow them.
     * @return array{status:int|null,body:string}
     */
    protected function request(string $method, string $path, string $json): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => max(1, (int) ceil($this->timeout)),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $json,
        ]);
        $response = curl_exec($ch);
        $status = $response === false ? null : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $response === false ? '' : $response];
    }
}
