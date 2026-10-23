<?php

declare(strict_types=1);

namespace Whisperr\Catalog;

use Whisperr\ConfigurationException;
use Whisperr\Whisperr;

/**
 * Catalog push client (whisperr-go catalog v2 contract, section 6):
 * $whisperr->catalog(), or new CatalogClient($serverKey).
 *
 * Calls are synchronous request/response, not buffered like track(): each
 * method returns once Whisperr has answered. Upserts are chunked at 500 items
 * (and 2 MiB), deletes at 1000. 429 and 5xx answers and network errors are
 * retried with backoff, honoring Retry-After. Every retry resends the same
 * body, and the server orders writes by updated_at / deleted_at, so a retried
 * upsert or delete can never apply twice or overwrite a newer write.
 *
 * Items and results use the wire field names (snake_case), so an array you
 * build is exactly what the server receives.
 */
class CatalogClient
{
    public const UPSERT_BATCH = 500;
    public const DELETE_BATCH = 1000;
    public const MAX_BODY_BYTES = 2097152;
    public const MAX_RETRY_AFTER_SECONDS = 60.0;

    private const DEFAULT_BASE = 'https://api.whisperr.net';
    private const SNAPSHOT_ID = '/^[A-Za-z0-9_-]{1,64}$/D';
    private const TIME_FIELDS = ['updated_at', 'published_at'];
    /** Keys whose values are JSON objects even when empty. */
    private const OBJECT_FIELDS = ['extras', 'localizations'];
    private const ENVELOPE = 16;

    private string $apiKey;
    private string $baseUrl;
    private int $maxRetries;
    private float $timeout;
    private bool $disabled;
    /** @var callable(string,string,array<string,string>,?string,float):array{status:int|null,headers:array<string,string>,body:string} */
    private $http;
    /** @var callable(float):void */
    private $sleep;

    /**
     * @param array<string,mixed> $options base_url, max_retries (5), timeout (30.0 seconds),
     *   http (callable(method, url, headers, ?body, timeout): array{status, headers, body}; status null = network error),
     *   sleep (callable(float seconds)), disabled
     */
    public function __construct(string $apiKey, array $options = [])
    {
        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim((string) ($options['base_url'] ?? self::DEFAULT_BASE), '/');
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 5));
        $this->timeout = (float) ($options['timeout'] ?? 30.0);
        $this->disabled = (bool) ($options['disabled'] ?? false);
        $this->http = $options['http'] ?? [self::class, 'curlRequest'];
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * Create or update items. Rejected items are listed in 'rejected', not thrown.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array{accepted:int,applied:int,unchanged:int,ignored_older:int,rejected:array<int,array{index:int,source_item_id:string,code:string,message:string}>}
     */
    public function upsert(array $items): array
    {
        $this->checkKey();
        $result = ['accepted' => 0, 'applied' => 0, 'unchanged' => 0, 'ignored_older' => 0, 'rejected' => []];
        $chunks = [];
        $chunk = [];
        $size = self::ENVELOPE;
        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                throw new ConfigurationException('each catalog item must be an array');
            }
            $wire = self::itemToWire($item);
            $id = (string) ($wire['source_item_id'] ?? '');
            $json = self::encode($wire);
            $n = strlen($json) + 1;
            if ($n + self::ENVELOPE > self::MAX_BODY_BYTES) {
                $result['rejected'][] = self::tooLarge($index, $id, 'the item is larger than the 2 MiB request limit');
                continue;
            }
            if (count($chunk) === self::UPSERT_BATCH || $size + $n > self::MAX_BODY_BYTES) {
                $chunks[] = $chunk;
                $chunk = [];
                $size = self::ENVELOPE;
            }
            $chunk[] = [$index, $id, $json];
            $size += $n;
        }
        if ($chunk) {
            $chunks[] = $chunk;
        }

        $send = function (array $part) use (&$send, &$result): void {
            try {
                $res = $this->request('POST', '/v1/catalog/items/batch', self::itemsBody($part));
            } catch (CatalogException $e) {
                // The server measures the body; split and resend rather than fail.
                if ($e->getStatus() === 413 && count($part) > 1) {
                    $half = intdiv(count($part) + 1, 2);
                    $send(array_slice($part, 0, $half));
                    $send(array_slice($part, $half));
                    return;
                }
                if ($e->getStatus() === 413) {
                    $result['rejected'][] = self::tooLarge($part[0][0], $part[0][1], $e->getMessage());
                    return;
                }
                throw $e->withPartial(self::sorted($result));
            }
            foreach (['accepted', 'applied', 'unchanged', 'ignored_older'] as $key) {
                $result[$key] += self::int($res[$key] ?? null);
            }
            array_push($result['rejected'], ...self::rejections($res['rejected'] ?? null, $part));
        };
        foreach ($chunks as $part) {
            $send($part);
        }
        return self::sorted($result);
    }

    /**
     * Delete items by id (all get $deletedAt, default now) or by
     * ['source_item_id' => ..., 'deleted_at' => ...]. Chunks at 1000.
     *
     * @param array<int,string|array<string,mixed>> $items
     * @param \DateTimeInterface|string|null $deletedAt
     * @return array{deleted:int,ignored_older:int,not_found:int,rejected:array<int,array{index:int,source_item_id:string,code:string,message:string}>}
     */
    public function delete(array $items, $deletedAt = null): array
    {
        $this->checkKey();
        // One timestamp for the whole call, so a retry resends the same body.
        $fallback = self::formatTime($deletedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')), 'deleted_at');
        $encoded = [];
        foreach (array_values($items) as $index => $item) {
            if (is_string($item)) {
                [$id, $at] = [$item, $fallback];
            } elseif (is_array($item)) {
                $id = (string) ($item['source_item_id'] ?? '');
                $at = isset($item['deleted_at']) ? self::formatTime($item['deleted_at'], 'deleted_at') : $fallback;
            } else {
                throw new ConfigurationException('each deletion is an id or an array with source_item_id');
            }
            $encoded[] = [$index, $id, self::encode(['source_item_id' => $id, 'deleted_at' => $at])];
        }
        $result = ['deleted' => 0, 'ignored_older' => 0, 'not_found' => 0, 'rejected' => []];
        foreach (array_chunk($encoded, self::DELETE_BATCH) as $part) {
            try {
                $res = $this->request('POST', '/v1/catalog/items/delete', self::itemsBody($part));
            } catch (CatalogException $e) {
                throw $e->withPartial(self::sorted($result));
            }
            foreach (['deleted', 'ignored_older', 'not_found'] as $key) {
                $result[$key] += self::int($res[$key] ?? null);
            }
            array_push($result['rejected'], ...self::rejections($res['rejected'] ?? null, $part));
        }
        return self::sorted($result);
    }

    /**
     * Start a full sync: upsert every item, then call completeSnapshot().
     * @return array{snapshot_id:string,started_at:string}
     */
    public function beginSnapshot(): array
    {
        $this->checkKey();
        $res = $this->request('POST', '/v1/catalog/snapshots', '{}');
        return ['snapshot_id' => self::str($res['snapshot_id'] ?? null), 'started_at' => self::str($res['started_at'] ?? null)];
    }

    /**
     * End a full sync: items not upserted since beginSnapshot() are removed.
     * @return array{tombstoned:int,item_count:int,state:string}
     */
    public function completeSnapshot(string $snapshotId, int $itemCount): array
    {
        $this->checkKey();
        if (!preg_match(self::SNAPSHOT_ID, $snapshotId)) {
            throw new ConfigurationException('snapshotId must be the id beginSnapshot() returned');
        }
        self::checkCount($itemCount);
        $res = $this->request('POST', "/v1/catalog/snapshots/$snapshotId/complete", self::encode(['item_count' => $itemCount]));
        return [
            'tombstoned' => self::int($res['tombstoned'] ?? null),
            'item_count' => self::int($res['item_count'] ?? null),
            'state' => self::str($res['state'] ?? null),
        ];
    }

    /**
     * Tell Whisperr the catalog is still current. Send every 30 minutes with
     * your live item count.
     * @return array{refreshed:bool,reason?:string,stored_count?:int}
     */
    public function heartbeat(int $itemCount): array
    {
        $this->checkKey();
        self::checkCount($itemCount);
        $res = $this->request('POST', '/v1/catalog/heartbeat', self::encode(['item_count' => $itemCount]));
        $out = ['refreshed' => ($res['refreshed'] ?? null) === true];
        if (is_string($res['reason'] ?? null)) {
            $out['reason'] = $res['reason'];
        }
        if (is_int($res['stored_count'] ?? null)) {
            $out['stored_count'] = $res['stored_count'];
        }
        return $out;
    }

    /**
     * The health of your pushed catalog (state, item_count, last_push_at, ...).
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $this->checkKey();
        return $this->request('GET', '/v1/catalog/status', null);
    }

    /**
     * A copy of $item for the wire: null values are left out and
     * DateTimeInterface values become RFC 3339 UTC strings.
     *
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function itemToWire(array $item): array
    {
        $wire = self::dropNull($item);
        foreach (self::TIME_FIELDS as $field) {
            if (array_key_exists($field, $wire)) {
                $wire[$field] = self::formatTime($wire[$field], $field);
            }
        }
        foreach (self::OBJECT_FIELDS as $field) {
            if (isset($wire[$field]) && is_array($wire[$field])) {
                $wire[$field] = (object) $wire[$field];
            }
        }
        return $wire;
    }

    /**
     * RFC 3339 in UTC with Z; milliseconds only when they are not zero. A
     * string is sent as it is.
     *
     * @param mixed $value
     */
    public static function formatTime($value, string $field): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
            $ms = intdiv((int) $utc->format('u'), 1000);
            $base = $utc->format('Y-m-d\TH:i:s');
            return $ms ? sprintf('%s.%03dZ', $base, $ms) : $base . 'Z';
        }
        throw new ConfigurationException("$field must be a DateTimeInterface or an RFC 3339 string");
    }

    /** Seconds to wait from a Retry-After value (delay-seconds or an HTTP-date), capped at 60; null when unreadable. */
    public static function parseRetryAfter(?string $value, ?float $now = null): ?float
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return min((float) $v, self::MAX_RETRY_AFTER_SECONDS);
        }
        if (!preg_match('/[a-z]/i', $v)) {
            return null;
        }
        $at = strtotime($v);
        if ($at === false) {
            return null;
        }
        return min(max(0.0, $at - ($now ?? microtime(true))), self::MAX_RETRY_AFTER_SECONDS);
    }

    // ---- internals ----

    private function checkKey(): void
    {
        if ($this->disabled) {
            throw new ConfigurationException('the Whisperr client is disabled; catalog calls are not sent');
        }
        if ($this->apiKey === '') {
            throw new ConfigurationException('catalog calls need a server key (wrk_...)');
        }
        if (strncmp($this->apiKey, 'wpk_', 4) === 0) {
            throw new ConfigurationException(
                'catalog push needs a server key (wrk_...); a publishable key (wpk_...) ships in apps and browsers and is refused'
            );
        }
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, ?string $body): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-API-Key' => $this->apiKey,
            'User-Agent' => Whisperr::SDK_NAME . '/' . Whisperr::VERSION,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $attempt = 0;
        while (true) {
            [$status, $code, $message, $retryAfter] = [0, 'network_error', 'network error or timeout', null];
            try {
                $response = ($this->http)($method, $this->baseUrl . $path, $headers, $body, $this->timeout);
            } catch (\Throwable $e) {
                $response = ['status' => null, 'headers' => [], 'body' => ''];
            }
            if (($response['status'] ?? null) !== null) {
                $status = (int) $response['status'];
                $parsed = json_decode((string) ($response['body'] ?? ''), true);
                if ($status >= 200 && $status < 300) {
                    if (!is_array($parsed)) {
                        throw new CatalogException("unreadable response from $path", $status, 'invalid_response', false);
                    }
                    return $parsed;
                }
                [$code, $message] = self::errorOf($parsed, $status);
                if ($status === 429 || $status === 503) {
                    $retryAfter = self::parseRetryAfter(self::header($response['headers'] ?? [], 'Retry-After'));
                }
                if (!($status === 429 || $status >= 500)) {
                    throw new CatalogException("$path refused ($status $code): $message", $status, $code, false);
                }
            }
            if ($attempt >= $this->maxRetries) {
                $shown = $status ?: 'network';
                throw new CatalogException(
                    sprintf('%s failed after %d attempts (%s %s): %s', $path, $attempt + 1, $shown, $code, $message),
                    $status,
                    $code,
                    true,
                );
            }
            $attempt++;
            ($this->sleep)($retryAfter ?? self::backoff($attempt));
        }
    }

    /**
     * One attempt over cURL, with redirects disabled so the key never follows them.
     *
     * @param array<string,string> $headers
     * @return array{status:int|null,headers:array<string,string>,body:string}
     */
    public static function curlRequest(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => max(1, (int) ceil($timeout)),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = $raw === false ? null : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw === false ? '' : (string) $raw];
    }

    /** @param mixed $value */
    private static function dropNull($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if ($v !== null) {
                $out[$k] = self::dropNull($v);
            }
        }
        // Keep lists as JSON arrays after nulls are removed.
        return self::isList($value) ? array_values($out) : $out;
    }

    /** array_is_list() for PHP 8.0. @param array<mixed> $a */
    private static function isList(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }

    /** @param mixed $value */
    private static function encode($value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ConfigurationException('a catalog item cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @param array<int,array{0:int,1:string,2:string}> $part */
    private static function itemsBody(array $part): string
    {
        return '{"items":[' . implode(',', array_column($part, 2)) . ']}';
    }

    /** @return array{index:int,source_item_id:string,code:string,message:string} */
    private static function tooLarge(int $index, string $id, string $message): array
    {
        return ['index' => $index, 'source_item_id' => $id, 'code' => 'item_too_large', 'message' => $message];
    }

    /**
     * @param mixed $raw
     * @param array<int,array{0:int,1:string,2:string}> $part
     * @return array<int,array{index:int,source_item_id:string,code:string,message:string}>
     */
    private static function rejections($raw, array $part): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $r) {
            $r = is_array($r) ? $r : [];
            $local = $r['index'] ?? null;
            $orig = is_int($local) && isset($part[$local]) ? $part[$local] : null;
            $out[] = [
                'index' => $orig ? $orig[0] : -1,
                'source_item_id' => is_string($r['source_item_id'] ?? null) ? $r['source_item_id'] : ($orig[1] ?? ''),
                'code' => self::str($r['code'] ?? null),
                'message' => self::str($r['message'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function sorted(array $result): array
    {
        usort($result['rejected'], static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        return $result;
    }

    /**
     * @param mixed $body
     * @return array{0:string,1:string}
     */
    private static function errorOf($body, int $status): array
    {
        $b = is_array($body) ? $body : [];
        $e = isset($b['error']) && is_array($b['error']) ? $b['error'] : $b;
        $code = is_string($e['code'] ?? null) && $e['code'] !== '' ? $e['code'] : "http_$status";
        return [$code, is_string($e['message'] ?? null) ? $e['message'] : ''];
    }

    /** @param array<string,string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                return (string) $v;
            }
        }
        return null;
    }

    private static function checkCount(int $n): void
    {
        if ($n < 0) {
            throw new ConfigurationException('itemCount must be a whole number, at least 0');
        }
    }

    private static function backoff(int $attempt): float
    {
        return min(30.0, 0.5 * (2 ** $attempt)) + mt_rand(0, 250) / 1000;
    }

    /** @param mixed $v */
    private static function int($v): int
    {
        return is_int($v) ? $v : 0;
    }

    /** @param mixed $v */
    private static function str($v): string
    {
        return is_string($v) ? $v : '';
    }
}
