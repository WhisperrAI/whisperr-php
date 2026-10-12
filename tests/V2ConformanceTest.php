<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\Whisperr;

/**
 * Runs the whisperr-spec conformance/v2.json sections that apply to server SDKs
 * (appliesTo contains "server"), with the comparison rules the fixture defines.
 */
final class V2ConformanceTest extends TestCase
{
    private const SPEC_URL = 'https://raw.githubusercontent.com/WhisperrAI/whisperr-spec/main/conformance/v2.json';
    private const GOVERNED_CONTEXT = ['app_version', 'app_build', 'platform', 'sdk_name', 'sdk_version', 'locale'];

    public function testServerSections(): void
    {
        $spec = $this->loadSpec();
        $ran = 0;
        foreach ($spec['sections'] as $sectionName => $section) {
            if (!in_array('server', $section['appliesTo'], true)) {
                continue;
            }
            foreach ($section['cases'] as $case) {
                $this->runCase("$sectionName/{$case['name']}", $case);
                $ran++;
            }
        }
        $this->assertGreaterThan(0, $ran, 'v2.json has no server section');
    }

    /** @param array<string,mixed> $case */
    private function runCase(string $name, array $case): void
    {
        $transport = new ScriptedTransport();
        $options = ['api_key' => 'wrk_test', 'transport' => $transport, 'max_retries' => 0];
        if (isset($case['config']['release'])) {
            $options['app_version'] = $case['config']['release'];
        }
        $client = new Whisperr($options);

        foreach ($case['steps'] as $step) {
            $op = array_key_first($step);
            $args = $step[$op];
            if ($op === 'track') {
                $client->track($args['externalUserId'], $args['eventType'], $args['properties'] ?? []);
            } elseif ($op === 'identify') {
                $client->identify($args['externalUserId'], ['traits' => $args['traits'] ?? null]);
            } else {
                $this->fail("$name: step \"$op\" has no server SDK mapping");
            }
            $client->flush();
        }

        $this->assertCount(count($case['expectedRequests']), $transport->requests, "$name: request count");
        foreach ($case['expectedRequests'] as $i => $expected) {
            $actual = $transport->requests[$i];
            $this->assertSame($expected['endpoint'], $actual['path'], "$name: request $i endpoint");
            if ($expected['endpoint'] === '/v1/identify') {
                $this->assertEquals($expected['body'], $actual['body'], "$name: request $i body");
                continue;
            }
            $this->assertCount(count($expected['events']), $actual['body']['events'], "$name: request $i events");
            foreach ($expected['events'] as $j => $event) {
                $this->assertEvent("$name: request $i event $j", $event, $actual['body']['events'][$j]);
            }
        }
    }

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $actual
     */
    private function assertEvent(string $label, array $expected, array $actual): void
    {
        $this->assertNotEmpty($actual['context']['$message_id'] ?? null, "$label: \$message_id");
        foreach ($expected as $key => $value) {
            if ($key === 'context') {
                continue;
            }
            $this->assertEquals($value, $actual[$key] ?? null, "$label: $key");
        }
        if (!array_key_exists('context', $expected)) {
            return;
        }
        foreach (self::GOVERNED_CONTEXT as $key) {
            if (array_key_exists($key, $expected['context'])) {
                $this->assertSame($this->resolve($expected['context'][$key]), $actual['context'][$key] ?? null, "$label: context.$key");
            } else {
                $this->assertArrayNotHasKey($key, $actual['context'], "$label: context.$key must be absent");
            }
        }
    }

    private function resolve(string $value): string
    {
        $placeholders = ['$sdk_name' => Whisperr::SDK_NAME, '$sdk_version' => Whisperr::VERSION];
        return $placeholders[$value] ?? $value;
    }

    /** @return array<string,mixed> */
    private function loadSpec(): array
    {
        $wire = getenv('WHISPERR_SPEC_PATH');
        $json = ($wire !== false && $wire !== '')
            ? @file_get_contents(dirname($wire) . DIRECTORY_SEPARATOR . 'v2.json')
            : @file_get_contents(self::SPEC_URL);
        if ($json === false) {
            $this->markTestSkipped('could not load conformance/v2.json');
        }
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
