<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\Catalog\CatalogClient;
use Whisperr\Catalog\CatalogException;
use Whisperr\ConfigurationException;
use Whisperr\Money;
use Whisperr\Whisperr;

final class CatalogTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private array $calls = [];

    /** @param array<int,mixed>|callable $replies */
    private function http($replies): callable
    {
        $this->calls = [];
        return function (string $method, string $url, array $headers, ?string $body) use ($replies): array {
            $parsed = $body === null ? null : json_decode($body, true);
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'raw' => $body, 'body' => $parsed];
            $r = is_callable($replies) ? $replies($parsed) : $replies[count($this->calls) - 1];
            if ($r instanceof \Throwable) {
                throw $r;
            }
            return ['status' => $r[0], 'headers' => $r[2] ?? [], 'body' => $r[1] === null ? '' : json_encode($r[1])];
        };
    }

    private static function ok(int $n): array
    {
        return [200, ['accepted' => $n, 'applied' => $n, 'unchanged' => 0, 'ignored_older' => 0, 'rejected' => []]];
    }

    private static function item(string $id, array $extra = []): array
    {
        return $extra + ['source_item_id' => $id, 'type' => 'product', 'name' => "Item $id",
            'availability' => 'available', 'updated_at' => '2026-10-23T09:00:00Z'];
    }

    private function client(callable $http, array $options = []): CatalogClient
    {
        return new CatalogClient('wrk_x', $options + ['base_url' => 'https://api.test', 'http' => $http,
            'sleep' => static function (float $s): void {
            }]);
    }

    public function testPublishableKeyIsRefusedFromTheMainClientWithoutARequest(): void
    {
        $w = new Whisperr(['api_key' => 'wpk_live_x', 'catalog_http' => $this->http([])]);
        try {
            $w->catalog()->upsert([self::item('a')]);
            $this->fail('expected ConfigurationException');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('server key', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    public function testDisabledClientRefusesCatalogCalls(): void
    {
        $w = new Whisperr(['disabled' => true]);
        $this->expectException(ConfigurationException::class);
        $w->catalog()->heartbeat(1);
    }

    public function testMainClientCatalogUsesItsKeyAndBase(): void
    {
        $w = new Whisperr(['api_key' => 'wrk_live_k', 'base_url' => 'https://eu.api.test/',
            'catalog_http' => $this->http([[200, ['refreshed' => true]]])]);
        $this->assertSame($w->catalog(), $w->catalog());
        $this->assertSame(['refreshed' => true], $w->catalog()->heartbeat(3));
        $this->assertSame('https://eu.api.test/v1/catalog/heartbeat', $this->calls[0]['url']);
        $this->assertSame('wrk_live_k', $this->calls[0]['headers']['X-API-Key']);
        $this->assertSame('whisperr-php/' . Whisperr::VERSION, $this->calls[0]['headers']['User-Agent']);
    }

    public function testRetries500AndNetworkErrorsWithBackoffAndTheSameBody(): void
    {
        $waits = [];
        $http = $this->http([[500, null], new \RuntimeException('connection reset'), self::ok(1)]);
        $res = $this->client($http, ['sleep' => static function (float $s) use (&$waits): void {
            $waits[] = $s;
        }])->upsert([self::item('a')]);
        $this->assertSame(1, $res['accepted']);
        $this->assertCount(3, $this->calls);
        $this->assertCount(1, array_unique(array_column($this->calls, 'raw')));
        $this->assertGreaterThanOrEqual(1.0, $waits[0]);
        $this->assertGreaterThanOrEqual(2.0, $waits[1]);
    }

    public function testExhaustedRetriesThrowRetryableWithPartial(): void
    {
        $http = $this->http([self::ok(500), [503, null], [503, null], [503, null]]);
        $items = [];
        for ($i = 0; $i < 501; $i++) {
            $items[] = self::item("s$i");
        }
        try {
            $this->client($http, ['max_retries' => 2])->upsert($items);
            $this->fail('expected CatalogException');
        } catch (CatalogException $e) {
            $this->assertTrue($e->isRetryable());
            $this->assertSame(503, $e->getStatus());
            $this->assertSame(500, $e->getPartial()['accepted']);
        }
        $this->assertCount(4, $this->calls);
    }

    public function testA4xxIsNotRetried(): void
    {
        $http = $this->http([[400, ['error' => ['code' => 'invalid_request', 'message' => 'bad']]]]);
        try {
            $this->client($http)->upsert([self::item('a')]);
            $this->fail('expected CatalogException');
        } catch (CatalogException $e) {
            $this->assertSame([400, 'invalid_request', false], [$e->getStatus(), $e->getErrorCode(), $e->isRetryable()]);
        }
        $this->assertCount(1, $this->calls);
    }

    public function test413SplitsAndRejectsALoneItemStillTooLarge(): void
    {
        $http = $this->http(static function ($body) {
            if (count($body['items']) > 1 || $body['items'][0]['source_item_id'] === 'big') {
                return [413, ['error' => ['code' => 'batch_too_large', 'message' => 'too large']]];
            }
            return self::ok(1);
        });
        $res = $this->client($http)->upsert([self::item('a'), self::item('big'), self::item('c')]);
        $this->assertSame(2, $res['accepted']);
        $this->assertCount(1, $res['rejected']);
        $this->assertSame([1, 'big', 'item_too_large'], [$res['rejected'][0]['index'], $res['rejected'][0]['source_item_id'], $res['rejected'][0]['code']]);
    }

    public function testChunksByBodySize(): void
    {
        $http = $this->http(static fn ($body) => self::ok(count($body['items'])));
        $items = [];
        for ($i = 0; $i < 400; $i++) {
            $items[] = self::item("s$i", ['tags' => array_fill(0, 60, str_repeat('x', 200))]);
        }
        $this->assertSame(400, $this->client($http)->upsert($items)['accepted']);
        $this->assertGreaterThan(1, count($this->calls));
        foreach ($this->calls as $call) {
            $this->assertLessThanOrEqual(CatalogClient::MAX_BODY_BYTES, strlen($call['raw']));
        }
    }

    public function testDatesNullsAndEmptyObjects(): void
    {
        $http = $this->http([self::ok(1)]);
        $this->client($http)->upsert([self::item('a', [
            'updated_at' => new \DateTimeImmutable('2026-10-23T09:00:00.120+02:00'),
            'published_at' => new \DateTime('2026-09-01T00:00:00Z'),
            'url' => null,
            'price' => ['amount_minor' => Money::toMinorUnits('19.99', 'USD'), 'currency' => 'USD', 'meaning' => null],
            'extras' => [],
            'tags' => ['a', null, 'b'],
        ])]);
        $raw = $this->calls[0]['raw'];
        $sent = $this->calls[0]['body']['items'][0];
        $this->assertSame('2026-10-23T07:00:00.120Z', $sent['updated_at']);
        $this->assertSame('2026-09-01T00:00:00Z', $sent['published_at']);
        $this->assertArrayNotHasKey('url', $sent);
        $this->assertSame(['amount_minor' => 1999, 'currency' => 'USD'], $sent['price']);
        $this->assertStringContainsString('"extras":{}', $raw);
        $this->assertStringContainsString('"tags":["a","b"]', $raw);
    }

    public function testDeleteUsesOneDeletedAtAcrossChunks(): void
    {
        $http = $this->http(static fn ($body) => [200, ['deleted' => count($body['items']), 'ignored_older' => 0, 'not_found' => 0, 'rejected' => []]]);
        $ids = [];
        for ($i = 0; $i < 1500; $i++) {
            $ids[] = "s$i";
        }
        $ids[] = ['source_item_id' => 'own', 'deleted_at' => '2026-01-01T00:00:00Z'];
        $res = $this->client($http)->delete($ids);
        $this->assertSame(1501, $res['deleted']);
        $stamps = [];
        foreach ($this->calls as $call) {
            foreach ($call['body']['items'] as $it) {
                if ($it['source_item_id'] !== 'own') {
                    $stamps[$it['deleted_at']] = true;
                }
            }
        }
        $this->assertCount(1, $stamps);
        $last = $this->calls[1]['body']['items'];
        $this->assertSame(['source_item_id' => 'own', 'deleted_at' => '2026-01-01T00:00:00Z'], end($last));
    }

    public function testCountsAreCheckedLocally(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client($this->http([]))->heartbeat(-1);
    }

    public function testParseRetryAfter(): void
    {
        $now = (float) gmmktime(12, 0, 0, 9, 26, 2026);
        $this->assertSame(7.0, CatalogClient::parseRetryAfter('7', $now));
        $this->assertSame(60.0, CatalogClient::parseRetryAfter('3600', $now));
        $this->assertSame(5.0, CatalogClient::parseRetryAfter('Sat, 26 Sep 2026 12:00:05 GMT', $now));
        $this->assertNull(CatalogClient::parseRetryAfter('1.5', $now));
        $this->assertNull(CatalogClient::parseRetryAfter(null, $now));
    }

    public function testMinorUnitsFromNumbers(): void
    {
        $this->assertSame(1999, Money::toMinorUnits(19.99, 'USD'));
        $this->assertSame(1500, Money::toMinorUnits(1500, 'JPY'));
        $this->assertSame(1500, Money::toMinorUnits(1500.0, 'JPY'));
        $this->assertSame(1234, Money::toMinorUnits(1.234, 'kwd'));
        foreach ([0.1 + 0.2, 1e-7, NAN, 1e300, -1] as $bad) {
            try {
                Money::toMinorUnits($bad, 'USD');
                $this->fail('expected InvalidArgumentException for ' . var_export($bad, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid amount', $e->getMessage());
            }
        }
    }
}
