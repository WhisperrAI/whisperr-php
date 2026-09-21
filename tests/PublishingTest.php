<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\Whisperr;
use Whisperr\WhisperrError;

final class PublishingTest extends TestCase
{
    private function client(ScriptedTransport $transport, array $options = []): Whisperr
    {
        return new Whisperr(array_merge(['api_key' => 'server_key', 'transport' => $transport, 'max_retries' => 0], $options));
    }

    private function receipt(string $disposition = 'retrying', bool $duplicate = false): array
    {
        return ['processing' => 'durably_accepted', 'delivery' => [
            'delivery_id' => 'delivery_1', 'disposition' => $disposition, 'duplicate' => $duplicate,
        ]];
    }

    public function testReplayAcrossNewClientsPreservesOriginalEventIdentityTimeAndUser(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(null, []);
        $transport->respond(202, $this->receipt('completed', true));
        $args = ['42', 'order_completed', ['order_id' => '99'], 'outbox_order_99', '2026-09-21T02:03:04.123+04:00', ['$message_id' => 'forged']];
        $first = $this->client($transport)->publish(...$args);
        $second = $this->client($transport)->publish(...$args);
        $this->assertFalse($first->acknowledged());
        $this->assertTrue($first->retryable());
        $this->assertTrue($second->acknowledged());
        $this->assertTrue($second->duplicate());
        $this->assertSame('delivery_1', $second->deliveryId());
        $this->assertSame(json_encode($transport->requests[0]), json_encode($transport->requests[1]));
        $this->assertSame('/v1/events/track', $transport->requests[0]['path']);
        $this->assertSame('42', $transport->requests[0]['body']['external_user_id']);
        $this->assertSame($args[4], $transport->requests[0]['body']['occurred_at']);
        $this->assertSame('outbox_order_99', $transport->requests[0]['body']['context']['$message_id']);
        $this->assertArrayNotHasKey('source_id', $transport->requests[0]['body']);
        $this->assertCount(2, $transport->requests, 'No additional queued or shutdown delivery');
    }

    public function testOnlyDurableUsableReceiptsAreAcknowledged(): void
    {
        foreach ([
            [202, $this->receipt(), true, false],
            [202, $this->receipt('completed'), true, false],
            [202, $this->receipt('quarantined'), false, false],
            [202, $this->receipt('dead_lettered'), false, false],
            [202, $this->receipt('suppressed'), false, false],
            [202, $this->receipt('unknown'), false, true],
            [202, ['accepted' => 1, 'rejected' => 0], false, true],
            [202, ['event' => ['id' => 'legacy']], false, true],
            [202, ['processing' => 'durably_accepted', 'delivery' => ['delivery_id' => '', 'disposition' => 'completed', 'duplicate' => false]], false, true],
            [400, ['error' => ['code' => 'invalid_request']], false, false],
            [401, [], false, false], [403, [], false, false],
            [429, [], false, true], [503, [], false, true], [null, [], false, true],
        ] as [$status, $body, $acknowledged, $retryable]) {
            $transport = new ScriptedTransport();
            $transport->respond($status, $body);
            $result = $this->client($transport)->publish('42', 'order_completed', [], 'mid', '2026-09-21T00:00:00Z');
            $this->assertSame($acknowledged, $result->acknowledged());
            $this->assertSame($retryable, $result->retryable());
            $this->assertSame($status, $result->status());
            $this->assertCount(1, $transport->requests, 'Outbox publisher must make a single attempt');
        }
    }

    public function testPartialAndMalformedBatchAcknowledgmentsRetainStableEvents(): void
    {
        foreach ([['accepted' => 1, 'rejected' => 1], ['accepted' => 2, 'rejected' => 1], ['accepted' => '2', 'rejected' => 0], []] as $response) {
            $transport = new ScriptedTransport();
            $transport->respond(202, $response);
            $transport->respond(202, ['accepted' => 2, 'rejected' => 0]);
            $client = $this->client($transport);
            $client->track('42', 'first_event');
            $client->track('42', 'second_event');
            $client->flush();
            $client->flush();
            $client->flush();
            $this->assertCount(2, $transport->requests);
            $this->assertSame(json_encode($transport->requests[0]), json_encode($transport->requests[1]));
        }
    }

    public function testInvalidReplayValuesNeverReachTransport(): void
    {
        foreach ([
            ['', 'order_completed', 'mid', '2026-09-21T00:00:00Z'],
            ['42', 'Order Completed', 'mid', '2026-09-21T00:00:00Z'],
            ['42', 'order_completed', '', '2026-09-21T00:00:00Z'],
            ['42', 'order_completed', str_repeat('x', 201), '2026-09-21T00:00:00Z'],
            ['42', 'order_completed', ' padded ', '2026-09-21T00:00:00Z'],
            ['42', 'order_completed', 'mid', 'tomorrow'],
            ['42', 'order_completed', 'mid', '2026-02-31T00:00:00Z'],
        ] as [$user, $type, $id, $time]) {
            $transport = new ScriptedTransport();
            try {
                $this->client($transport)->publish($user, $type, [], $id, $time);
                $this->fail('Invalid replay data was accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame([], $transport->requests);
            }
        }
    }

    public function testDisabledClientDoesNotAcknowledgeOrDeliverOutboxEvent(): void
    {
        $transport = new ScriptedTransport();
        $result = $this->client($transport, ['disabled' => true])->publish('42', 'order_completed', [], 'mid', '2026-09-21T00:00:00Z');
        $this->assertFalse($result->acknowledged());
        $this->assertFalse($result->retryable());
        $this->assertSame('disabled', $result->errorCode());
        $this->assertSame([], $transport->requests);
    }

    public function testOldCustomTransportsRemainUsableButCannotFabricateAcknowledgments(): void
    {
        $client = new Whisperr(['api_key' => 'key', 'transport' => new FakeTransport()]);
        $this->expectException(\LogicException::class);
        $client->publish('42', 'order_completed', [], 'mid', '2026-09-21T00:00:00Z');
    }

    public function testErrorsRemainReadOnlyOnPhp80(): void
    {
        $error = new WhisperrError('auth', 'Rejected', 401);
        $this->assertSame('auth', $error->type);
        $this->assertSame(401, $error->status);
        $this->expectException(\LogicException::class);
        $error->type = 'success';
    }
}
