<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\Whisperr;

final class ConformanceTest extends TestCase
{
    private const SPEC_URL = 'https://raw.githubusercontent.com/WhisperrAI/whisperr-spec/main/conformance/wire.json';

    /** @return array<string,mixed> */
    private function loadSpec(): array
    {
        $local = getenv('WHISPERR_SPEC_PATH');
        $json = $local ? @file_get_contents($local) : @file_get_contents(self::SPEC_URL);
        if ($json === false) {
            $this->markTestSkipped('could not load wire spec');
        }
        return json_decode($json, true);
    }

    public function testWireConformance(): void
    {
        $spec = $this->loadSpec();
        $this->assertNotEmpty($spec['cases']);

        foreach ($spec['cases'] as $case) {
            $transport = new CapturingTransport('https://api.whisperr.net', 'wrk_test', 10.0, fn ($m) => null);
            $client = new Whisperr(['api_key' => 'wrk_test', 'transport' => $transport]);
            $this->applyCase($client, $case);
            $client->flush();

            $this->assertSame($case['endpoint'], $transport->captured['path'] ?? null, $case['name']);
            // Normalize via a JSON round-trip so object/array casts match the wire.
            $body = json_decode(json_encode($transport->captured['body']), true);

            if ($case['op'] === 'track') {
                $event = $body['events'][0];
                foreach (($case['expectedEvent'] ?? []) as $k => $v) {
                    $this->assertEquals($v, $event[$k], "{$case['name']}.$k");
                }
                foreach (($case['contextMustContain'] ?? []) as $key) {
                    $this->assertArrayHasKey($key, $event['context'], "{$case['name']} context.$key");
                }
                if ($case['occurredAtRfc3339Z'] ?? false) {
                    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $event['occurred_at']);
                }
            } else {
                foreach (($case['expectedBody'] ?? []) as $k => $v) {
                    $this->assertEquals($v, $body[$k], "{$case['name']}.$k");
                }
            }
        }
    }

    /** @param array<string,mixed> $case */
    private function applyCase(Whisperr $client, array $case): void
    {
        $s = $case['scenario'];
        if ($case['op'] === 'track') {
            $client->track($s['externalUserId'], $s['eventType'], $s['properties'] ?? []);
            return;
        }
        $params = [];
        foreach (['traits' => 'traits', 'email' => 'email', 'phone' => 'phone'] as $from => $to) {
            if (isset($s[$from])) {
                $params[$to] = $s[$from];
            }
        }
        if (isset($s['pushToken'])) {
            $params['push_token'] = $s['pushToken'];
        }
        if (isset($s['preferredChannel'])) {
            $params['preferred_channel'] = $s['preferredChannel'];
        }
        if (isset($s['channels'])) {
            $params['channels'] = array_map(static function ($ch) {
                $c = ['type' => $ch['type'], 'address' => $ch['address'], 'opted_in' => $ch['optedIn'] ?? true];
                if (array_key_exists('verified', $ch)) {
                    $c['verified'] = $ch['verified'];
                }
                return $c;
            }, $s['channels']);
        }
        $client->identify($s['externalUserId'], $params);
    }
}
