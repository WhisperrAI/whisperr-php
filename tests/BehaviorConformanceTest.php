<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\Whisperr;
use Whisperr\WhisperrError;

final class BehaviorConformanceTest extends TestCase
{
    private const SPEC_URL = 'https://raw.githubusercontent.com/WhisperrAI/whisperr-spec/main/conformance/behavior.json';

    public function testBehaviorConformance(): void
    {
        $spec = $this->loadSpec();
        $this->assertNotEmpty($spec['cases']);

        foreach ($spec['cases'] as $case) {
            $scenario = $case['scenario'];
            $errors = [];
            $transport = new FakeTransport($case['firstResponse']['classification']);
            $client = new Whisperr([
                'api_key' => 'wrk_test',
                'transport' => $transport,
                'max_retries' => $case['clientOptions']['maxRetries'] ?? 0,
                'on_error' => function (WhisperrError $error) use (&$errors): void {
                    $errors[] = $error;
                },
            ]);

            $client->track(
                $scenario['externalUserId'],
                $scenario['eventType'],
                $scenario['properties'] ?? [],
            );
            $client->flush();

            $this->assertContainsError($case['expect']['errorType'], $errors, $case['name']);
            $this->assertSame(1, count($transport->batches), $case['name'] . ': first delivery attempt');
            $this->assertSame(
                $case['expect']['retainedAfterFirstFlush'],
                $this->pendingCount($client) > 0,
                $case['name'] . ': retained after first flush',
            );

            $attemptsBefore = count($transport->batches);
            $transport->setResult($case['recoveryResponse']['classification']);
            $client->flush();

            $attemptsAfter = count($transport->batches);
            $retried = $attemptsAfter > $attemptsBefore;
            $this->assertSame($case['expect']['retriesAfterRecovery'], $retried, $case['name'] . ': retried after recovery');

            $delivered = $retried
                && $transport->batches[$attemptsAfter - 1][0]['event_type'] === $scenario['eventType'];
            $this->assertSame($case['expect']['deliveredAfterRecovery'], $delivered, $case['name'] . ': delivered after recovery');

            if (($case['expect']['stableMessageIdOnRetry'] ?? false) === true) {
                $this->assertSame(
                    $transport->batches[0][0]['message_id'],
                    $transport->batches[1][0]['message_id'],
                    $case['name'] . ': stable message id on retry',
                );
            }
        }
    }

    /** @return array{cases: array<int,array<string,mixed>>} */
    private function loadSpec(): array
    {
        $local = getenv('WHISPERR_BEHAVIOR_SPEC_PATH') ?: $this->siblingBehaviorPath();
        if ($local !== null && $local !== '') {
            $raw = file_get_contents($local);
            if ($raw === false) {
                $this->fail('Unable to read behavior spec at ' . $local);
            }
            return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        }

        $raw = @file_get_contents(self::SPEC_URL);
        if ($raw === false) {
            $this->markTestSkipped('Unable to fetch behavior spec; set WHISPERR_SPEC_PATH or WHISPERR_BEHAVIOR_SPEC_PATH');
        }
        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private function siblingBehaviorPath(): ?string
    {
        $wire = getenv('WHISPERR_SPEC_PATH');
        if ($wire === false || $wire === '') {
            return null;
        }
        return dirname($wire) . DIRECTORY_SEPARATOR . 'behavior.json';
    }

    /** @param array<int,WhisperrError> $errors */
    private function assertContainsError(string $type, array $errors, string $caseName): void
    {
        $types = array_map(fn (WhisperrError $e): string => $e->type, $errors);
        $this->assertContains($type, $types, $caseName . ': emitted expected error');
    }

    private function pendingCount(Whisperr $client): int
    {
        $prop = new \ReflectionProperty($client, 'queue');
        $prop->setAccessible(true);
        return count($prop->getValue($client));
    }
}
