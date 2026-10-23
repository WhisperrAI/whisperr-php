<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\Catalog\CatalogClient;
use Whisperr\Catalog\CatalogException;
use Whisperr\ConfigurationException;
use Whisperr\Money;

/**
 * Catalog push conformance (tests/fixtures/catalog_push.json).
 * tests/fixtures/catalog_push.json is a byte-identical copy of whisperr-spec
 * conformance/catalog_push.json. Point WHISPERR_CATALOG_SPEC_PATH at a spec
 * checkout's file to run that copy instead and to check the bundled one has
 * not drifted from it. PHP items and results use the wire names, so fixture
 * inputs are passed as is.
 */
final class CatalogConformanceTest extends TestCase
{
    private const BUNDLED = __DIR__ . '/fixtures/catalog_push.json';

    private static function spec(): array
    {
        $path = getenv('WHISPERR_CATALOG_SPEC_PATH') ?: self::BUNDLED;
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Client-side reject codes carry an SDK-defined message: check it is set, not its text. */
    private function expectedResult(array $case, $result): array
    {
        $want = $case['result'];
        $client = self::spec()['reject_codes']['client'];
        foreach ($want['rejected'] ?? [] as $i => $r) {
            if (in_array($r['code'], $client, true)) {
                $message = $result['rejected'][$i]['message'] ?? null;
                $this->assertIsString($message);
                $this->assertNotSame('', trim($message));
                $want['rejected'][$i]['message'] = $message;
            }
        }
        return $want;
    }

    public function testBundledFixtureIsByteIdenticalToTheSpecCopy(): void
    {
        $spec = getenv('WHISPERR_CATALOG_SPEC_PATH');
        if (!$spec) {
            $this->markTestSkipped('WHISPERR_CATALOG_SPEC_PATH is not set');
        }
        $this->assertSame(file_get_contents($spec), file_get_contents(self::BUNDLED));
    }

    public function testTheOnlySdkWrittenRejectCodeIsListedAsClientSide(): void
    {
        // item_too_large is the only code the SDK adds; the server never sends it.
        $codes = self::spec()['reject_codes'];
        $this->assertSame(['item_too_large'], $codes['client']);
        $this->assertNotContains('item_too_large', $codes['server']);
    }

    public static function cases(): array
    {
        $out = [];
        foreach (self::spec()['cases'] as $case) {
            $out[$case['name']] = [$case];
        }
        return $out;
    }

    public static function minorUnits(): array
    {
        $out = [];
        foreach (self::spec()['minor_units'] as $m) {
            $out[$m['amount'] . ' ' . $m['currency']] = [$m];
        }
        return $out;
    }

    /** @dataProvider cases */
    public function testCase(array $case): void
    {
        $sent = [];
        $exchanges = $case['exchanges'];
        $http = function (string $method, string $url, array $headers, ?string $body) use (&$sent, $exchanges, $case): array {
            $x = $exchanges[count($sent)] ?? null;
            if ($x === null) {
                throw new \LogicException("{$case['name']}: unexpected request $method $url");
            }
            $sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'raw' => $body,
                'body' => $body === null ? null : json_decode($body, true)];
            $r = $x['response'];
            return ['status' => $r['status'], 'headers' => $r['headers'] ?? [],
                'body' => array_key_exists('body', $r) ? json_encode($r['body']) : ''];
        };
        $client = new CatalogClient($case['api_key'] ?? 'wrk_test', [
            'base_url' => 'https://api.test', 'http' => $http, 'sleep' => static function (float $s): void {
            },
        ]);
        $result = null;
        $error = null;
        try {
            $result = $this->runCase($client, $case);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertCount(count($exchanges), $sent, $error ? $error->getMessage() : 'request count');
        foreach ($exchanges as $i => $x) {
            $req = $x['request'];
            $got = $sent[$i];
            $this->assertSame($req['method'], $got['method']);
            $this->assertSame($req['path'], parse_url($got['url'], PHP_URL_PATH));
            $this->assertSame($case['api_key'] ?? 'wrk_test', $got['headers']['X-API-Key']);
            if ($req['method'] === 'GET') {
                $this->assertNull($got['raw']);
            } else {
                $this->assertSame('application/json', $got['headers']['Content-Type']);
            }
            if (array_key_exists('body', $req)) {
                // Compare as JSON so {} and [] stay distinct.
                $this->assertJsonStringEqualsJsonString(json_encode((object) $req['body'] ?: new \stdClass()), $got['raw']);
            }
            if (isset($req['item_ids'])) {
                $this->assertSame($req['item_ids'], array_column($got['body']['items'], 'source_item_id'));
            }
            if (isset($req['item_count'])) {
                $items = $got['body']['items'];
                $this->assertCount($req['item_count'], $items);
                $this->assertSame($req['first_item_id'], $items[0]['source_item_id']);
                $this->assertSame($req['last_item_id'], $items[count($items) - 1]['source_item_id']);
            }
            if (!empty($req['same_body_as_previous'])) {
                $this->assertSame($sent[$i - 1]['raw'], $got['raw']);
            }
        }

        if (isset($case['error'])) {
            $want = $case['error'];
            if (!empty($want['configuration']) || !empty($want['local'])) {
                $this->assertInstanceOf(ConfigurationException::class, $error);
            } else {
                $this->assertInstanceOf(CatalogException::class, $error);
                $this->assertSame($want['status'], $error->getStatus());
                $this->assertSame($want['code'], $error->getErrorCode());
                $this->assertSame($want['retryable'], $error->isRetryable());
            }
            return;
        }
        if ($error) {
            throw $error;
        }
        $this->assertEquals($this->expectedResult($case, $result), $result);
    }

    /** @dataProvider minorUnits */
    public function testMinorUnits(array $m): void
    {
        if (isset($m['error'])) {
            $this->expectException(\InvalidArgumentException::class);
        }
        $value = Money::toMinorUnits($m['amount'], $m['currency']);
        if (!isset($m['error'])) {
            $this->assertSame($m['minor'], $value);
        }
    }

    private function runCase(CatalogClient $client, array $case)
    {
        $a = $case['args'];
        switch ($case['op']) {
            case 'upsert':
                $items = $a['items'] ?? null;
                if ($items === null) {
                    $g = $a['generate_items'];
                    $items = [];
                    for ($i = 0; $i < $g['count']; $i++) {
                        $items[] = $g['template'] + ['source_item_id' => $g['source_item_id_prefix'] . $i];
                    }
                }
                return $client->upsert($items);
            case 'delete':
                if (isset($a['items'])) {
                    return $client->delete($a['items']);
                }
                $ids = $a['ids'] ?? null;
                if ($ids === null) {
                    $ids = [];
                    for ($i = 0; $i < $a['generate_ids']['count']; $i++) {
                        $ids[] = $a['generate_ids']['prefix'] . $i;
                    }
                }
                return $client->delete($ids, $a['deleted_at'] ?? null);
            case 'begin_snapshot':
                return $client->beginSnapshot();
            case 'complete_snapshot':
                return $client->completeSnapshot($a['snapshot_id'], $a['item_count']);
            case 'heartbeat':
                return $client->heartbeat($a['item_count']);
            case 'status':
                return $client->status();
        }
        throw new \LogicException('unknown op ' . $case['op']);
    }
}
