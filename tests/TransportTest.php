<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private function transport(): CapturingTransport
    {
        return new CapturingTransport('https://api.whisperr.net/', 'wrk_test', 10.0, fn ($m) => null);
    }

    public function testBatchWireShape(): void
    {
        $t = $this->transport();
        $result = $t->sendBatch([[
            'external_user_id' => 'u1',
            'event_type' => 'x',
            'occurred_at' => '2026-01-01T00:00:00.000Z',
            'properties' => ['a' => 1],
            'context' => ['k' => 'v'],
            'message_id' => 'mid1',
        ]]);

        $this->assertSame('ok', $result);
        $this->assertSame('/v1/events/batch', $t->captured['path']);
        $event = $t->captured['body']['events'][0];
        $this->assertSame('u1', $event['external_user_id']);
        $this->assertSame('mid1', $event['context']['$message_id']);
        $this->assertSame('v', $event['context']['k']);
    }

    public function testIdentifyChannelMapping(): void
    {
        $t = $this->transport();
        $t->sendIdentify([
            'external_user_id' => 'u1',
            'traits' => ['plan' => 'pro'],
            'channels' => [['type' => 'email', 'address' => 'a@b.com', 'opted_in' => true]],
        ]);

        $this->assertSame('/v1/identify', $t->captured['path']);
        $this->assertSame(
            [['channel' => 'email', 'address' => 'a@b.com', 'opted_in' => true]],
            $t->captured['body']['channels'],
        );
    }

    public function testIdentifyAcceptsWireShapedExplicitChannels(): void
    {
        $t = $this->transport();
        $t->sendIdentify([
            'external_user_id' => 'u1',
            'channels' => [['channel' => 'sms', 'address' => '+15551234567', 'verified' => true]],
        ]);

        $this->assertSame(
            [['channel' => 'sms', 'address' => '+15551234567', 'opted_in' => true, 'verified' => true]],
            $t->captured['body']['channels'],
        );
    }

    public function testEmptyBatchShortCircuits(): void
    {
        $t = $this->transport();
        $this->assertSame('ok', $t->sendBatch([]));
        $this->assertSame([], $t->captured); // post() never reached
    }
}
