<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use PHPUnit\Framework\TestCase;
use Whisperr\Whisperr;
use Whisperr\WhisperrError;

final class WhisperrTest extends TestCase
{
    private function client(FakeTransport $t, array $opts = []): Whisperr
    {
        return new Whisperr(array_merge([
            'api_key' => 'wrk_test',
            'transport' => $t,
        ], $opts));
    }

    public function testTrackDeliversEventsWithExplicitUserAndMessageId(): void
    {
        $t = new FakeTransport();
        $w = $this->client($t);
        $w->track('user_8842', 'payment_failed', ['amount_cents' => 4900]);
        $w->track('user_8842', 'subscription_cancelled');
        $w->flush();

        $events = array_merge(...$t->batches);
        $this->assertCount(2, $events);
        $byType = [];
        foreach ($events as $e) {
            $byType[$e['event_type']] = $e;
        }
        $this->assertSame('user_8842', $byType['payment_failed']['external_user_id']);
        $this->assertSame(['amount_cents' => 4900], $byType['payment_failed']['properties']);
        $this->assertStringEndsWith('Z', $byType['payment_failed']['occurred_at']);
        $ids = array_map(fn ($e) => $e['message_id'], $events);
        $this->assertCount(2, array_unique($ids));
    }

    public function testIdentifyCarriesTraitsAndChannels(): void
    {
        $t = new FakeTransport();
        $w = $this->client($t);
        $w->identify('user_8842', [
            'traits' => ['plan' => 'pro'],
            'email' => 'a@b.com',
            'preferred_channel' => 'email',
        ]);
        $w->flush();

        $this->assertCount(1, $t->identifies);
        $op = $t->identifies[0];
        $this->assertSame('user_8842', $op['external_user_id']);
        $this->assertSame(['plan' => 'pro'], $op['traits']);
        $this->assertSame('email', $op['preferred_channel']);
        $this->assertSame([['type' => 'email', 'address' => 'a@b.com', 'opted_in' => true]], $op['channels']);
    }

    public function testTrackRequiresUserAndEventType(): void
    {
        $t = new FakeTransport();
        $w = $this->client($t);
        $w->track('', 'payment_failed');
        $w->track('user_1', '');
        $w->flush();
        $this->assertSame([], $t->batches);
    }

    public function testInvalidEventTypeIsDroppedBeforeItCanPoisonBatch(): void
    {
        $t = new FakeTransport();
        $errors = [];
        $w = $this->client($t, ['on_error' => function (WhisperrError $e) use (&$errors) {
            $errors[] = $e;
        }]);
        $w->track('user_1', 'User Signed Up');
        $w->track('user_1', 'checkout_completed');
        $w->flush();

        $this->assertContainsError('dropped', $errors);
        $events = array_merge(...$t->batches);
        $this->assertSame(['checkout_completed'], array_column($events, 'event_type'));
    }

    public function testAuthFailureEmitsAndStops(): void
    {
        $t = new FakeTransport('auth');
        $errors = [];
        $w = $this->client($t, ['on_error' => function (WhisperrError $e) use (&$errors) {
            $errors[] = $e;
        }]);
        $w->track('user_1', 'feature_used');
        $w->flush();
        $this->assertcontainsError('auth', $errors);
    }

    public function testDropOn4xx(): void
    {
        $t = new FakeTransport('drop');
        $errors = [];
        $w = $this->client($t, ['on_error' => function (WhisperrError $e) use (&$errors) {
            $errors[] = $e;
        }]);
        $w->track('user_1', 'feature_used');
        $w->flush();
        $this->assertcontainsError('dropped', $errors);
    }

    public function testRetryExhaustedIsBounded(): void
    {
        $t = new FakeTransport('retry');
        $errors = [];
        $w = $this->client($t, [
            'max_retries' => 0,
            'on_error' => function (WhisperrError $e) use (&$errors) {
                $errors[] = $e;
            },
        ]);
        $w->track('user_1', 'feature_used');
        $w->flush();
        $this->assertcontainsError('retry_exhausted', $errors);
    }

    public function testFailedEventsAreRetainedAndRetried(): void
    {
        // A delivery that fails on auth must keep the events buffered so a later
        // flush retries the SAME events instead of silently dropping them.
        $t = new FakeTransport('auth');
        $w = $this->client($t);
        $w->track('user_1', 'feature_used');
        $w->flush(); // auth → event retained
        $attemptsBefore = count($t->batches);

        $t->setResult('ok');
        $w->flush(); // retries the retained event, now succeeds
        $this->assertCount($attemptsBefore + 1, $t->batches);
        $last = $t->batches[count($t->batches) - 1];
        $this->assertSame('feature_used', $last[0]['event_type']);
    }

    public function testDisabledIsNoop(): void
    {
        $t = new FakeTransport();
        $w = new Whisperr(['api_key' => 'wrk_test', 'transport' => $t, 'disabled' => true]);
        $w->track('user_1', 'feature_used');
        $w->flush();
        $this->assertSame([], $t->batches);
        $this->assertSame([], $t->identifies);
    }

    public function testMissingApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Whisperr(['api_key' => '']);
    }

    public function testFlushAtTriggersAutoFlush(): void
    {
        $t = new FakeTransport();
        $w = $this->client($t, ['flush_at' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $w->track('user_1', "event_$i");
        }
        // Reached flush_at without an explicit flush() call.
        $this->assertCount(3, array_merge(...$t->batches));
    }

    /** @param array<int,WhisperrError> $errors */
    private function assertContainsError(string $type, array $errors): void
    {
        $types = array_map(fn (WhisperrError $e) => $e->type, $errors);
        $this->assertContains($type, $types);
    }
}
