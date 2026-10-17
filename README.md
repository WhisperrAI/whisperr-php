# whisperr-php

The Whisperr **server-side** SDK for PHP, with first-class Laravel support —
reliable churn-signal event tracking for any PHP backend. The backend is where
the highest-signal churn events live (payment failures, cancellations, trial
expiry, usage drops), so this is where Whisperr gets its most valuable signal.

```bash
composer require whisperr/php
```

## Laravel

The service provider and `Whisperr` facade auto-register via package discovery.
Set your key and (optionally) publish the config:

```bash
php artisan vendor:publish --tag=whisperr-config
```

```dotenv
# .env
WHISPERR_API_KEY=wrk_...
WHISPERR_APP_VERSION=2026.10.1   # optional: your backend release
```

```php
use Whisperr\Laravel\Facades\Whisperr;

// In a controller — source the user id explicitly:
Whisperr::track(auth()->id(), 'plan_upgraded', ['plan' => 'pro']);

// In a Stripe webhook / job — use the id from your domain data:
Whisperr::track($subscription->user_id, 'billing_issue_started', ['failure_code' => 'card_declined']);

// Associate traits / contact channels.
// The email shortcut marks the address opted in. Send it only for a user
// who agreed to email, according to your own records.
Whisperr::identify(auth()->id(), ['traits' => ['plan' => 'pro'], 'email' => $user->email]);
```

Events are buffered during the request and flushed **after the response is sent**
(via the app's `terminating` hook), so tracking adds no latency to the response.

## Plain PHP

```php
use Whisperr\Whisperr;

$whisperr = new Whisperr(['api_key' => getenv('WHISPERR_API_KEY')]);

$whisperr->track('user_8842', 'membership_ended', ['end_reason' => 'billing']);

$whisperr->flush(); // also auto-flushes on shutdown
```

The user id (`external_user_id`) is **always explicit** — the server has no
session to infer it from. Pass the same id you use everywhere else for that user,
and frontend + backend events land on one timeline automatically.

## User language (`app_language`)

`app_language` is the language the user picked in your product (not the device
or browser locale). Whisperr writes messages in this language. Send it as a
trait on `identify()`. Use a BCP 47 tag, such as `de` or `pt-BR`:

```php
$whisperr->identify($user->id, ['traits' => ['app_language' => 'pt-BR']]);
```

The server SDK has no special API for it, and it does not change the value.
The server keeps the last value it got. Send it again when the user changes
the language.

## Billing events

Whisperr reserves these billing fallback event names (playbook engine spec
section 3.8). Send them from your backend, never from the front end. Use the
`Whisperr\BillingEvent` constants and `BillingEvent::properties()`:

| Constant | Event name |
|---|---|
| `BillingEvent::TRIAL_STARTED` | `trial_started` |
| `BillingEvent::TRIAL_CONVERTED` | `trial_converted` |
| `BillingEvent::TRIAL_AUTO_RENEW_DISABLED` | `trial_auto_renew_disabled` |
| `BillingEvent::MEMBERSHIP_STARTED` | `membership_started` |
| `BillingEvent::RENEWAL_SUCCEEDED` | `renewal_succeeded` |
| `BillingEvent::AUTO_RENEW_DISABLED` | `auto_renew_disabled` |
| `BillingEvent::AUTO_RENEW_ENABLED` | `auto_renew_enabled` |
| `BillingEvent::MEMBERSHIP_ENDED` | `membership_ended` |
| `BillingEvent::BILLING_ISSUE_STARTED` | `billing_issue_started` |
| `BillingEvent::BILLING_RECOVERED` | `billing_recovered` |
| `BillingEvent::REFUND_GRANTED` | `refund_granted` |

`properties()` builds the money properties: `plan_id`, `price`, `currency`,
`plan_period`, `transaction_id`, `store`. Put the other properties of an event
(see section 3.8) in `extra`.

```php
use Whisperr\BillingEvent;

$whisperr->track($membership->user_id, BillingEvent::MEMBERSHIP_STARTED, BillingEvent::properties(
    planId: 'pro_monthly',
    price: 9.99,          // major units: 9.99, not 999 cents
    currency: 'USD',      // ISO 4217; the helper changes 'usd' to 'USD'
    planPeriod: 'P1M',    // ISO 8601 duration
    transactionId: 'txn_123',
    store: 'stripe',
    extra: ['is_first_ever' => true, 'current_period_ends_at' => '2026-11-12T08:00:00Z'],
), messageId: "membership_started_{$membership->id}", occurredAt: $membership->started_at->toIso8601ZuluString());

$whisperr->track($payment->user_id, BillingEvent::RENEWAL_SUCCEEDED, BillingEvent::properties(
    planId: 'pro_monthly',
    price: 9.99,
    currency: 'USD',
    planPeriod: 'P1M',
    transactionId: (string) $payment->id,
    store: 'stripe',
    extra: ['paid_periods_count' => 2, 'current_period_ends_at' => '2026-12-12T08:00:00Z'],
), messageId: "renewal_{$payment->id}", occurredAt: $payment->paid_at->toIso8601ZuluString());
```

- An argument you do not give adds no property.
- `properties()` throws `InvalidArgumentException` when `currency` is not three
  letters, or `price` is negative or not a finite number.
- Billing code can run twice (cron, payment callbacks). Pass a stable
  `messageId` and the real billing time as `occurredAt` (see
  [Idempotent server events](#idempotent-server-events)).
- From an outbox worker, use the same properties with `publish()` (see
  [Durable backend outcomes](#durable-backend-outcomes)).
- **Register the event names first.** Today the server stores these events only
  after your app registers them. The Whisperr MCP does this for you. A later
  server change will accept them without registration.

## Versions on every event

Every event carries `context.sdk_name` (`whisperr-php`) and
`context.sdk_version` (`Whisperr::VERSION`). Set `app_version` (Laravel:
`WHISPERR_APP_VERSION`) to your backend release, and every event also carries
`context.app_version`. When `app_version` is empty, the SDK sends no
`app_version` key. A key you put in the `$context` argument of one event wins.

## Design

- **Same wire contract as the other Whisperr SDKs.** Events post to
  `/v1/events/batch`, identities to `/v1/identify`, authenticated with
  `X-API-Key`.
- **Request-friendly.** Events buffer in memory and deliver in a batch on
  `flush()` — automatically after the Laravel response (or on shutdown). Retries
  are bounded so request teardown never hangs.
- **Reliable in-process.** Batching, retry with backoff (429/5xx), malformed-4xx
  drop, per-event idempotency key. On `401/403` or exhausted retries, the failed
  batch stays buffered and is retried on the next `flush()` rather than being
  dropped. The buffer lives for the request only — it is not crash-durable.
- **No Composer dependencies.** Uses ext-curl + ext-json only.

## Options (plain-PHP constructor)

| Key | Default | Notes |
|---|---|---|
| `api_key` | — | App ingestion key (`wrk_…`). Required. |
| `base_url` | `https://api.whisperr.net` | Ingestion base URL. |
| `flush_at` | `100` | Auto-flush when this many events are buffered. |
| `max_batch_size` | `500` | Events per batch (hard backend cap is 500). |
| `max_retries` | `3` | Retries before giving up a batch. |
| `request_timeout` | `10.0` | Per-request timeout (seconds). |
| `app_version` | — | Your backend release. Sent as `context.app_version` on every event. |
| `disabled` | `false` | No-op client (useful in tests). |
| `debug` | `false` | Verbose logging via `error_log`. |
| `on_error` | — | `callable(WhisperrError): void` for observability. |


## Runtime support

PHP 8.0 or later is supported. The runtime package uses only `ext-curl` and
`ext-json`; Laravel is optional. CI tests PHP 8.0 with Illuminate 8.83 and
PHPUnit 9.6, and PHP 8.1–8.3 with compatible Illuminate 10/11 versions. This
includes service-provider registration and termination behavior.

## Idempotent server events

A cron job or a payment callback can run twice. To record such an event only
once, pass a stable message ID and the original occurrence time to `track()`:

```php
Whisperr::track(
    (string) $invoice->user_id,
    'billing_issue_started',
    ['failure_code' => 'card_declined'],
    messageId: "stripe_invoice_{$invoice->id}_billing_issue_started",
    occurredAt: $invoice->failed_at->toIso8601ZuluString(), // e.g. 2026-10-12T08:00:00Z
);
```

- The server keeps one event per message ID for each app. A second `track()`
  with the same ID is a no-op. Build the ID from your domain data, not from the
  current time.
- The message ID must be nonempty, have no leading or trailing spaces, and be
  at most 200 bytes.
- `occurredAt` must be RFC3339 with a timezone (`Z` or `+04:00`). The server
  accepts times up to 30 days in the past and 5 minutes in the future.
- An invalid message ID or time drops the event with a `dropped` error and a
  debug warning. `track()` never throws.
- Retries inside `flush()` resend the same ID and time.
- Without these arguments, `track()` uses a random ID and the current time, as
  before.

## Durable backend outcomes

Use `publish()` from a durable outbox worker when losing a committed outcome is
unacceptable. Unlike `track()`, it is synchronous, makes **one HTTP attempt**,
and returns an authoritative ingestion receipt. It requires a server ingestion
key **bound to the approved Whisperr source producer**. A legacy key's generic
2xx response is not accepted as proof of durable source ingestion: with an
unbound key, every `publish()` returns a retryable `invalid_receipt` result.

Insert an outbox row in the **same database transaction** as the business change.
Persist its unique event ID, original RFC3339 occurrence timestamp, stable user
ID and immutable event payload. Do not send the HTTP request inside that
transaction. A scheduled worker claims committed rows and uses:

```php
$result = $whisperr->publish(
    (string) $row->external_user_id,
    $row->event_type,
    $row->properties,
    $row->message_id,
    $row->occurred_at, // original RFC3339 timestamp, never the retry time
);

if ($result->acknowledged()) {
    // Atomically acknowledge this claimed outbox row; keep the receipt for audit.
    $outbox->acknowledge($row->id, $result->deliveryId());
} elseif ($result->retryable()) {
    $outbox->scheduleRetry($row->id); // bounded exponential backoff + jitter
} else {
    $outbox->flagForReview($row->id, $result->errorCode());
}
```

`$outbox` above represents your application's persistent storage, not an SDK
class. Protect claims against concurrent workers, retain failures for inspection,
and retry after a worker crash or ambiguous timeout using the **same message ID,
time, user and payload**. IDs must be nonempty and at most 200 bytes. The ingestion
API currently rejects occurrences older than 30 days or over five minutes in the
future; retain these failures for review rather than rewriting their timestamps.

`PublishResult` exposes `acknowledged()`, `retryable()`, `status()`, `deliveryId()`,
`disposition()`, `duplicate()` and `errorCode()`. A `retrying` receipt means Whisperr
owns durable processing; `completed` includes an already processed duplicate.
Neither claims that a message was sent or a business conversion occurred.
`quarantined`, `dead_lettered` and `suppressed` receipts remain visible failures
for review. Authentication and permanent request errors are not retried blindly.
Missing/malformed receipts, rate limits, server errors and network failures can be
retried with the same ID. A disabled client never acknowledges an outbox row.

The existing `track()`/`flush()` API remains request-buffered and follows the
shared SDK delivery contract: any `2xx` batch response dequeues the batch. Events
the server counts as `rejected` failed shape validation and are not resent.
Use `publish()` for durable outcomes, where individual receipts matter. Custom
transports can opt in through `PublisherTransportInterface`; older transports
remain compatible with buffered tracking.

---

Whisperr — predict churn, automate interventions, recover revenue.
[whisperr.net](https://whisperr.net)
