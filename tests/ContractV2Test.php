<?php

declare(strict_types=1);

namespace Whisperr\Tests;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Whisperr\BillingEvent;
use Whisperr\Laravel\WhisperrServiceProvider;
use Whisperr\Whisperr;

/** Spec contract v2 for server SDKs: C2 (app_language), C5 (versions), C6 (billing names). */
final class ContractV2Test extends TestCase
{
    private const RECEIPT = ['processing' => 'durably_accepted', 'delivery' => [
        'delivery_id' => 'd1', 'disposition' => 'retrying', 'duplicate' => false,
    ]];

    /** @param array<string,mixed> $options */
    private function client(ScriptedTransport $transport, array $options = []): Whisperr
    {
        return new Whisperr(array_merge(['api_key' => 'wrk_test', 'transport' => $transport, 'max_retries' => 0], $options));
    }

    /** @return array<string,mixed> the wire JSON of the first request, decoded */
    private function wire(ScriptedTransport $transport, int $i = 0): array
    {
        return json_decode((string) json_encode($transport->requests[$i]['body']), true);
    }

    // ---- C5 ----

    public function testEveryTrackedEventCarriesSdkNameAndVersionOnTheWire(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, ['accepted' => 2, 'rejected' => 0]);
        $client = $this->client($transport);
        $client->track('u1', 'feature_used');
        $client->track('u1', 'plan_upgraded', ['plan' => 'pro'], ['ip' => '203.0.113.9']);
        $client->flush();

        foreach ($this->wire($transport)['events'] as $event) {
            $this->assertSame('whisperr-php', $event['context']['sdk_name']);
            $this->assertSame(Whisperr::VERSION, $event['context']['sdk_version']);
            $this->assertArrayHasKey('$message_id', $event['context']);
            $this->assertArrayNotHasKey('app_version', $event['context'], 'no value, no key');
            $this->assertArrayNotHasKey('locale', $event['context']);
        }
        $this->assertSame('203.0.113.9', $this->wire($transport)['events'][1]['context']['ip']);
    }

    public function testConfiguredAppVersionIsSentOnTrackAndPublish(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, ['accepted' => 1, 'rejected' => 0]);
        $transport->respond(202, self::RECEIPT);
        $client = $this->client($transport, ['app_version' => ' 2026.10.1 ']);
        $client->track('u1', 'feature_used');
        $client->flush();
        $client->publish('u1', 'order_completed', [], 'outbox_1', '2026-10-12T00:00:00Z');

        $this->assertSame('2026.10.1', $this->wire($transport, 0)['events'][0]['context']['app_version']);
        $published = $this->wire($transport, 1)['context'];
        $this->assertSame('2026.10.1', $published['app_version']);
        $this->assertSame('whisperr-php', $published['sdk_name']);
        $this->assertSame(Whisperr::VERSION, $published['sdk_version']);
        $this->assertSame('outbox_1', $published['$message_id']);
    }

    public function testEmptyAppVersionSendsNoKey(): void
    {
        foreach (['', '   ', null] as $value) {
            $transport = new ScriptedTransport();
            $transport->respond(202, self::RECEIPT);
            $this->client($transport, ['app_version' => $value])
                ->publish('u1', 'order_completed', [], 'outbox_1', '2026-10-12T00:00:00Z');
            $this->assertArrayNotHasKey('app_version', $this->wire($transport)['context']);
        }
    }

    public function testCallerContextWinsButMessageIdStaysSdkOwned(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, ['accepted' => 1, 'rejected' => 0]);
        $client = $this->client($transport, ['app_version' => '1.0.0']);
        $client->track('u1', 'feature_used', [], ['app_version' => '1.0.1-hotfix', '$message_id' => 'forged']);
        $client->flush();

        $context = $this->wire($transport)['events'][0]['context'];
        $this->assertSame('1.0.1-hotfix', $context['app_version']);
        $this->assertNotSame('forged', $context['$message_id']);
    }

    public function testRetainedEventKeepsTheSameContextOnRetry(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(503, []);
        $transport->respond(202, ['accepted' => 1, 'rejected' => 0]);
        $client = $this->client($transport, ['app_version' => '3.2.1']);
        $client->track('u1', 'feature_used');
        $client->flush();
        $client->flush();

        $this->assertCount(2, $transport->requests);
        $this->assertSame(json_encode($transport->requests[0]['body']), json_encode($transport->requests[1]['body']));
    }

    public function testLaravelConfigPassesAppVersion(): void
    {
        $app = new class extends Container {
            public function configurationIsCached(): bool { return true; }
            public function configPath($path = ''): string { return '/tmp/config/' . $path; }
            public function terminating($callback): void {}
        };
        $app->instance('config', new Repository(['whisperr' => ['api_key' => 'wrk_test', 'app_version' => '2026.10.1']]));
        (new WhisperrServiceProvider($app))->register();
        $client = $app->make(Whisperr::class);

        $prop = new \ReflectionProperty($client, 'environmentContext');
        if (PHP_VERSION_ID < 80100) {
            $prop->setAccessible(true);
        }
        $this->assertSame('2026.10.1', $prop->getValue($client)['app_version']);
    }

    public function testVersionConstantMatchesLatestChangelogEntry(): void
    {
        $changelog = (string) file_get_contents(__DIR__ . '/../CHANGELOG.md');
        $this->assertMatchesRegularExpression('/^## \[?(\d+\.\d+\.\d+)/m', $changelog);
        preg_match('/^## \[?(\d+\.\d+\.\d+)/m', $changelog, $m);
        $this->assertSame($m[1], Whisperr::VERSION);
    }

    // ---- C2 ----

    public function testAppLanguageTraitPassesThroughIdentifyUnchanged(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, ['processing' => 'queued']);
        $client = $this->client($transport);
        $client->identify('u1', ['traits' => ['app_language' => 'pt-BR', 'plan' => 'pro']]);
        $client->flush();

        $this->assertSame('/v1/identify', $transport->requests[0]['path']);
        $this->assertSame(['app_language' => 'pt-BR', 'plan' => 'pro'], $this->wire($transport)['traits']);
    }

    // ---- C6 ----

    public function testBillingEventNamesMatchTheSpec(): void
    {
        $this->assertSame([
            'trial_started',
            'trial_converted',
            'trial_auto_renew_disabled',
            'membership_started',
            'renewal_succeeded',
            'auto_renew_disabled',
            'auto_renew_enabled',
            'membership_ended',
            'billing_issue_started',
            'billing_recovered',
            'refund_granted',
        ], BillingEvent::ALL);
        foreach (BillingEvent::ALL as $name) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $name);
        }
    }

    public function testBillingEventNamesMatchTheSpecSchema(): void
    {
        $wire = getenv('WHISPERR_SPEC_PATH');
        $schema = ($wire !== false && $wire !== '')
            ? @file_get_contents(dirname($wire, 2) . '/schemas/wire.schema.json')
            : false;
        if ($schema === false) {
            $this->markTestSkipped('set WHISPERR_SPEC_PATH to a whisperr-spec checkout');
        }
        $defs = json_decode($schema, true, 512, JSON_THROW_ON_ERROR)['$defs'];
        if (!isset($defs['billing_event'])) {
            $this->markTestSkipped('this spec has no billing_event definition');
        }
        $this->assertSame($defs['billing_event']['properties']['event_type']['enum'], BillingEvent::ALL);
        $specProperties = array_keys($defs['billing_event']['properties']['properties']['properties']);
        foreach (array_keys(BillingEvent::properties('p', 1.0, 'USD', 'P1M', 't', 's')) as $key) {
            $this->assertContains($key, $specProperties, "money property $key is in the spec");
        }
    }

    public function testBillingPropertiesOnTheWire(): void
    {
        $transport = new ScriptedTransport();
        $transport->respond(202, ['accepted' => 1, 'rejected' => 0]);
        $client = $this->client($transport);
        $client->track('u1', BillingEvent::RENEWAL_SUCCEEDED, BillingEvent::properties(
            planId: 'pro_monthly',
            price: 9.99,
            currency: ' gel ',
            planPeriod: 'P1M',
            transactionId: 'txn_123',
            store: 'app_store',
        ), messageId: 'renewal_42', occurredAt: '2026-10-12T08:00:00Z');
        $client->flush();

        $raw = (string) json_encode($transport->requests[0]['body']);
        $this->assertStringContainsString('"price":9.99', $raw);
        $this->assertSame([
            'plan_id' => 'pro_monthly',
            'price' => 9.99,
            'currency' => 'GEL',
            'plan_period' => 'P1M',
            'transaction_id' => 'txn_123',
            'store' => 'app_store',
        ], $this->wire($transport)['events'][0]['properties']);
        $event = $this->wire($transport)['events'][0];
        $this->assertSame('renewal_succeeded', $event['event_type']);
        $this->assertSame('2026-10-12T08:00:00Z', $event['occurred_at']);
        $this->assertSame('renewal_42', $event['context']['$message_id']);
        $this->assertSame('whisperr-php', $event['context']['sdk_name']);
    }

    public function testBillingPropertiesOmitNullsCastPriceAndKeepExtras(): void
    {
        $this->assertSame([], BillingEvent::properties());
        $props = BillingEvent::properties(price: 10, currency: 'USD', extra: ['price' => 'ignored', 'failure_code' => 'card_declined']);
        $this->assertSame(['price' => 10.0, 'failure_code' => 'card_declined', 'currency' => 'USD'], $props);
        $this->assertIsFloat($props['price']);
    }

    public function testBillingPropertiesRejectBadCurrencyAndNonFinitePrice(): void
    {
        foreach ([['currency' => 'US'], ['currency' => 'USDT'], ['currency' => '978'], ['price' => INF], ['price' => NAN], ['price' => -1.5]] as $args) {
            try {
                BillingEvent::properties(...$args);
                $this->fail('accepted ' . json_encode(array_keys($args)));
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
