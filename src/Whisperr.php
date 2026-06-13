<?php

declare(strict_types=1);

namespace Whisperr;

use Whisperr\Contracts\TransportInterface;

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
        $this->flushAt = (int) ($options['flush_at'] ?? 100);
        $this->maxBatch = min((int) ($options['max_batch_size'] ?? 500), 500);
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
     * @param array<string,mixed> $properties
     * @param array<string,mixed> $context
     */
    public function track(string $externalUserId, string $eventType, array $properties = [], array $context = []): void
    {
        if ($this->disabled || $externalUserId === '' || $eventType === '') {
            return;
        }
        if ($this->debug && !preg_match(self::SNAKE_CASE, $eventType)) {
            $this->warn("event_type \"$eventType\" is not snake_case — the server will reject it");
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

    /** Deliver everything currently buffered. Safe to call repeatedly. */
    public function flush(): void
    {
        if ($this->disabled || empty($this->queue)) {
            return;
        }
        $ops = $this->queue;
        $this->queue = [];

        $tracks = array_values(array_filter($ops, static fn ($o) => $o['kind'] === 'track'));
        $idents = array_values(array_filter($ops, static fn ($o) => $o['kind'] === 'identify'));

        foreach (array_chunk($tracks, $this->maxBatch) as $chunk) {
            $this->deliver(fn () => $this->transport->sendBatch($chunk), count($chunk));
        }
        foreach ($idents as $op) {
            $this->deliver(fn () => $this->transport->sendIdentify($op), 1);
        }
    }

    // ---- internals ----

    private function maybeFlush(): void
    {
        if (count($this->queue) >= $this->flushAt) {
            $this->flush();
        }
    }

    /** @param callable():string $send */
    private function deliver(callable $send, int $count): void
    {
        $retries = 0;
        while (true) {
            $result = $send();
            if ($result === 'ok') {
                return;
            }
            if ($result === 'drop') {
                $this->emit('dropped', "dropped $count event(s) — rejected by server");
                return;
            }
            if ($result === 'auth') {
                $this->emit('auth', 'delivery paused — API key rejected', 401);
                return;
            }
            $retries++;
            if ($retries > $this->maxRetries) {
                $this->emit('retry_exhausted', 'delivery failed after retries');
                return;
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
