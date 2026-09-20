<?php

declare(strict_types=1);

namespace Whisperr;

use Whisperr\Contracts\TransportInterface;
use Whisperr\Contracts\PublisherTransportInterface;
use Whisperr\Contracts\MessageHistoryTransportInterface;
use Whisperr\Contracts\IdentityTransportInterface;

/**
 * Whisperr server-side SDK for PHP.
 *
 * PHP is request-scoped with no background threads, so events are buffered in
 * memory during the request and delivered in a batch when flush() runs — call
 * it explicitly, or rely on the auto-flush registered with the runtime
 * (register_shutdown_function; in Laravel the service provider flushes after the
 * response is sent, adding no response latency).
 *
 * track()/identify() take the end-user id as the first argument — the server has
 * no session to infer it from. Pass the same id you use everywhere else.
 */
class Whisperr
{
    private const SNAKE_CASE = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';
    private const DEFAULT_BASE = 'https://api.whisperr.net';

    private string $apiKey;
    private bool $disabled;
    private int $flushAt;
    private int $maxBatch;
    private int $maxRetries;
    private bool $debug;
    /** @var callable(WhisperrError):void|null */
    private $onError;
    private TransportInterface $transport;

    /** @var array<int,array<string,mixed>> */
    private array $queue = [];

    /** @param array<string,mixed> $options */
    public function __construct(array $options)
    {
        $this->disabled = (bool) ($options['disabled'] ?? false);
        $this->apiKey = (string) ($options['api_key'] ?? '');
        if ($this->apiKey === '' && !$this->disabled) {
            throw new \InvalidArgumentException('api_key is required');
        }
        $baseUrl = rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE), '/');
        $this->flushAt = max(1, (int) ($options['flush_at'] ?? 100));
        $this->maxBatch = max(1, min((int) ($options['max_batch_size'] ?? 500), 500));
        $this->maxRetries = (int) ($options['max_retries'] ?? 3);
        $this->debug = (bool) ($options['debug'] ?? false);
        $this->onError = $options['on_error'] ?? null;

        $timeout = (float) ($options['request_timeout'] ?? 10.0);
        $this->transport = $options['transport']
            ?? new Transport($baseUrl, $this->apiKey, $timeout, fn (string $m) => $this->warn($m));

        if (!$this->disabled) {
            // Safety net so events aren't lost if the host never calls flush().
            register_shutdown_function([$this, 'flush']);
        }
    }

    /** @param array<string,mixed> $params traits, email, phone, push_token, preferred_channel, channels */
    public function identify(string $externalUserId, array $params = []): void
    {
        if ($this->disabled || $externalUserId === '') {
            return;
        }
        $this->queue[] = [
            'kind' => 'identify',
            'external_user_id' => $externalUserId,
            'traits' => $params['traits'] ?? null,
            'preferred_channel' => $params['preferred_channel'] ?? null,
            'channels' => $params['channels'] ?? $this->buildChannels($params),
        ];
        $this->maybeFlush();
    }

    /**
     * Synchronously commit an identity/contact update; useful when the caller
     * must confirm channel registration or exact-token revocation before return.
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function identifyNow(string $externalUserId, array $params = []): array
    {
        if (trim($externalUserId) === '' || trim($externalUserId) !== $externalUserId) {
            throw new \InvalidArgumentException('externalUserId must be a nonempty, unpadded stable user ID');
        }
        if ($this->disabled) {
            throw new RequestException('disabled', null);
        }
        if (!$this->transport instanceof IdentityTransportInterface) {
            throw new \LogicException('This transport does not support synchronous identity updates');
        }
        return $this->transport->identifyNow([
            'external_user_id' => $externalUserId,
            'traits' => $params['traits'] ?? null,
            'preferred_channel' => $params['preferred_channel'] ?? null,
            'channels' => $params['channels'] ?? $this->buildChannels($params),
        ]);
    }

    /**
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $context
     */
    public function track(string $externalUserId, string $eventType, array $properties = [], array $context = []): void
    {
        $eventType = trim($eventType);
        if ($this->disabled || $externalUserId === '' || $eventType === '') {
            return;
        }
        if (!preg_match(self::SNAKE_CASE, $eventType)) {
            $this->emit('dropped', "invalid event_type \"$eventType\" — expected snake_case");
            $this->warn("invalid event_type \"$eventType\" — event was not queued");
            return;
        }
        $this->queue[] = [
            'kind' => 'track',
            'external_user_id' => $externalUserId,
            'event_type' => $eventType,
            'properties' => $properties,
            'context' => $context,
            'occurred_at' => $this->nowIso(),
            'message_id' => $this->uuid(),
        ];
        $this->maybeFlush();
    }

    /**
     * Publish one persisted outbox event with one HTTP attempt. The caller owns
     * retry scheduling and must reuse the same ID, timestamp and payload.
     * Requires a server key bound to a Whisperr source producer.
     *
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $context
     */
    public function publish(
        string $externalUserId,
        string $eventType,
        array $properties,
        string $messageId,
        string $occurredAt,
        array $context = []
    ): PublishResult {
        if (trim($externalUserId) === '' || $externalUserId !== trim($externalUserId)) {
            throw new \InvalidArgumentException('externalUserId must be a nonempty, unpadded stable user ID');
        }
        if (!preg_match(self::SNAKE_CASE, $eventType)) {
            throw new \InvalidArgumentException('eventType must be snake_case');
        }
        if ($messageId === '' || trim($messageId) !== $messageId || strlen($messageId) > 200) {
            throw new \InvalidArgumentException('messageId must be a nonempty, unpadded string of at most 200 bytes');
        }
        // Do not normalize or replace an original occurrence time on replay.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $occurredAt)) {
            throw new \InvalidArgumentException('occurredAt must be an RFC3339 timestamp with a timezone');
        }
        $parts = date_parse($occurredAt);
        if ($parts['error_count'] || $parts['warning_count']) {
            throw new \InvalidArgumentException('occurredAt must be a valid date and time');
        }
        if ($this->disabled) {
            return new PublishResult(false, false, null, null, null, false, 'disabled');
        }
        if (!$this->transport instanceof PublisherTransportInterface) {
            throw new \LogicException('This transport does not support acknowledged publishing');
        }
        return $this->transport->publishEvent([
            'external_user_id' => $externalUserId,
            'event_type' => $eventType,
            'properties' => $properties,
            'context' => $context,
            'occurred_at' => $occurredAt,
            'message_id' => $messageId,
        ]);
    }

    /** @return array<string,mixed> */
    public function messageHistory(string $externalUserId, int $limit = 50, ?string $cursor = null): array
    {
        $this->assertHistoryAvailable($externalUserId);
        if ($limit < 1 || $limit > 100 || ($cursor !== null && ($cursor === '' || strlen($cursor) > 4096))) {
            throw new \InvalidArgumentException('Invalid message history limit or cursor');
        }
        return $this->transport->messageHistory($externalUserId, $limit, $cursor);
    }

    /** @return array<string,mixed> */
    public function message(string $externalUserId, string $messageId): array
    {
        $this->assertHistoryAvailable($externalUserId);
        if (trim($messageId) === '') {
            throw new \InvalidArgumentException('messageId is required');
        }
        return $this->transport->message($externalUserId, $messageId);
    }

    private function assertHistoryAvailable(string $externalUserId): void
    {
        if (trim($externalUserId) === '') {
            throw new \InvalidArgumentException('externalUserId is required');
        }
        if ($this->disabled) {
            throw new RequestException('disabled', null);
        }
        if (!$this->transport instanceof MessageHistoryTransportInterface) {
            throw new \LogicException('This transport does not support message history');
        }
    }

    /** Deliver everything currently buffered. Safe to call repeatedly. */
    public function flush(): void
    {
        if ($this->disabled || empty($this->queue)) {
            return;
        }
        // Take ownership of the buffer and deliver it batch by batch, in order.
        // Events are removed only once delivered (or intentionally dropped). On
        // auth failure or exhausted retries we put the failed batch back in
        // front of whatever's left and stop, so a later flush() retries the
        // same events instead of losing them.
        $ops = $this->queue;
        $this->queue = [];

        while ($ops) {
            if ($ops[0]['kind'] === 'identify') {
                $batch = array_splice($ops, 0, 1);
                $result = $this->deliver(fn () => $this->transport->sendIdentify($batch[0]), 1);
            } else {
                // A leading run of track ops, up to the batch cap.
                $n = 0;
                while ($n < count($ops) && $n < $this->maxBatch && $ops[$n]['kind'] === 'track') {
                    $n++;
                }
                $batch = array_splice($ops, 0, $n);
                $result = $this->deliver(fn () => $this->transport->sendBatch($batch), count($batch));
            }

            if ($result === 'ok' || $result === 'drop') {
                continue;
            }
            // auth / retry_exhausted: retain the failed batch + the remainder.
            $this->queue = array_merge($batch, $ops);
            return;
        }
    }

    // ---- internals ----

    private function maybeFlush(): void
    {
        if (count($this->queue) >= $this->flushAt) {
            $this->flush();
        }
    }

    /**
     * Send one batch, retrying transient failures with backoff. Returns the
     * terminal outcome so flush() can decide whether to drop or retain the batch.
     *
     * @param callable():string $send
     * @return 'ok'|'drop'|'auth'|'retry_exhausted'
     */
    private function deliver(callable $send, int $count): string
    {
        $retries = 0;
        while (true) {
            $result = $send();
            if ($result === 'ok') {
                return 'ok';
            }
            if ($result === 'drop') {
                $this->emit('dropped', "dropped $count event(s) — rejected by server");
                return 'drop';
            }
            if ($result === 'auth') {
                $this->emit('auth', 'delivery paused — API key rejected', 401);
                return 'auth';
            }
            $retries++;
            if ($retries > $this->maxRetries) {
                $this->emit('retry_exhausted', 'delivery failed after retries; will retry on next flush');
                return 'retry_exhausted';
            }
            usleep((int) ($this->backoff($retries) * 1_000_000));
        }
    }

    /** @param array<string,mixed> $p */
    private function buildChannels(array $p): ?array
    {
        $out = [];
        if (!empty($p['email'])) {
            $out[] = ['type' => 'email', 'address' => $p['email'], 'opted_in' => true];
        }
        if (!empty($p['phone'])) {
            $out[] = ['type' => 'sms', 'address' => $p['phone'], 'opted_in' => true];
        }
        if (!empty($p['push_token'])) {
            $out[] = ['type' => 'push', 'address' => $p['push_token'], 'opted_in' => true];
        }
        return $out ?: null;
    }

    private function backoff(int $attempt): float
    {
        // Bounded — flush may run during request teardown; don't hang the worker.
        $base = min(2.0, 0.5 * (2 ** $attempt));
        return $base + (mt_rand(0, 250) / 1000);
    }

    private function nowIso(): string
    {
        $t = microtime(true);
        $ms = sprintf('%03d', (int) (($t - floor($t)) * 1000));
        return gmdate('Y-m-d\TH:i:s', (int) $t) . '.' . $ms . 'Z';
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    private function emit(string $type, string $message, ?int $status = null): void
    {
        if (!$this->onError) {
            return;
        }
        try {
            ($this->onError)(new WhisperrError($type, $message, $status));
        } catch (\Throwable $e) {
            // host callback threw — ignore
        }
    }

    private function warn(string $msg): void
    {
        if ($this->debug) {
            error_log("[whisperr] $msg");
        }
    }
}
