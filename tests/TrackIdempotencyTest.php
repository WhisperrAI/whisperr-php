<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\Whisperr;
use Whisperr\WhisperrError;

final class TrackIdempotencyTest extends TestCase
{
    /** @param array<int,WhisperrError> $errors */
    private function client(ScriptedTransport $transport, array &$errors = []): Whisperr
    {
        return new Whisperr([
            'api_key' => 'server_key',
            'transport' => $transport,
            'max_retries' => 0,
            'on_error' => function (WhisperrError $e) use (&$errors) {
                $errors[] = $e;
            },
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    private function wireEvents(ScriptedTransport $transport): array
    {
        $events = [];
        foreach ($transport->requests as $request) {
            $this->assertSame('/v1/events/batch', $request['path']);
            $events = array_merge($events, $request['body']['events']);
        }
        return $events;
    }

    public function testStableMessageIdAndOccurredAtGoOnTheWireUnchanged(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, ['accepted' => 2, 'rejected' => 0]);
        $w = $this->client($transport);
        $args = ['42', 'renewal_succeeded', ['plan' => 'pro'], ['$message_id' => 'forged', 'source' => 'cron'],
            'sub_77_renewal_2026_10', '2026-10-01T03:00:00.250+04:00'];
        $w->track(...$args);
        $w->track(...$args);
        $w->flush();

        $events = $this->wireEvents($transport);
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('sub_77_renewal_2026_10', $event['context']['$message_id']);
            $this->assertSame('cron', $event['context']['source']);
            $this->assertSame('2026-10-01T03:00:00.250+04:00', $event['occurred_at']);
        }
    }

    public function testNamedArgumentsWithoutContext(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, []);
        $w = $this->client($transport);
        $w->track('42', 'payment_failed', [], messageId: 'inv_9_failed', occurredAt: '2026-10-12T08:00:00Z');
        $w->flush();

        $event = $this->wireEvents($transport)[0];
        $this->assertSame(
            ['sdk_name' => 'whisperr-php', 'sdk_version' => \Whisperr\Whisperr::VERSION, '$message_id' => 'inv_9_failed'],
            $event['context'],
        );
        $this->assertSame('2026-10-12T08:00:00Z', $event['occurred_at']);
    }

    public function testDefaultsStayRandomIdAndCurrentTime(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, []);
        $w = $this->client($transport);
        $before = time();
        $w->track('42', 'feature_used');
        $w->track('42', 'feature_used', [], ['$message_id' => 'ignored_without_explicit_id']);
        $w->flush();

        $events = $this->wireEvents($transport);
        $this->assertCount(2, $events);
        $ids = array_map(fn ($e) => $e['context']['$message_id'], $events);
        $this->assertCount(2, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }
        foreach ($events as $event) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $event['occurred_at']);
            $this->assertGreaterThanOrEqual($before, strtotime($event['occurred_at']));
        }
    }

    public function testInvalidMessageIdOrOccurredAtIsDroppedWithoutThrowing(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, []);
        $errors = [];
        $w = $this->client($transport, $errors);
        foreach ([
            ['', null],
            [' padded', null],
            ["padded\n", null],
            [str_repeat('x', 201), null],
            [str_repeat('é', 101), null], // 202 bytes
            ['ok_id', ''],
            ['ok_id', 'tomorrow'],
            ['ok_id', '2026-10-12 08:00:00Z'],
            ['ok_id', '2026-10-12T08:00:00'], // no timezone
            ['ok_id', '2026-02-31T00:00:00Z'],
            [null, '1760256000'],
        ] as [$id, $time]) {
            $w->track('42', 'payment_failed', [], [], $id, $time);
        }
        $w->track('42', 'payment_failed', [], [], str_repeat('x', 200), '2026-10-12T08:00:00.123456Z');
        $w->flush();

        $events = $this->wireEvents($transport);
        $this->assertCount(1, $events, 'Only the valid event is sent');
        $this->assertSame(str_repeat('x', 200), $events[0]['context']['$message_id']);
        $this->assertSame('2026-10-12T08:00:00.123456Z', $events[0]['occurred_at']);
        $this->assertCount(11, $errors);
        foreach ($errors as $error) {
            $this->assertSame('dropped', $error->type);
        }
    }

    public function testRetriedBatchReusesTheSameIdAndTime(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(503, []);
        $transport->respond(202, []);
        $w = $this->client($transport);
        $w->track('42', 'payment_failed');
        $w->track('42', 'renewal_succeeded', [], [], 'sub_77_renewal', '2026-10-01T00:00:00Z');
        $w->flush(); // 503 with max_retries 0: the batch is retained
        $w->flush(); // the retained batch is resent

        $this->assertCount(2, $transport->requests);
        $this->assertSame(json_encode($transport->requests[0]['body']), json_encode($transport->requests[1]['body']));
        $this->assertSame('sub_77_renewal', $transport->requests[1]['body']['events'][1]['context']['$message_id']);
    }
}
