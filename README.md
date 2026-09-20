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
```

```php
use Whisperr\Laravel\Facades\Whisperr;

// In a controller — source the user id explicitly:
Whisperr::track(auth()->id(), 'plan_upgraded', ['plan' => 'pro']);

// In a Stripe webhook / job — use the id from your domain data:
Whisperr::track($subscription->user_id, 'payment_failed', ['amount_cents' => 4900]);

// Associate traits / contact channels:
Whisperr::identify(auth()->id(), ['traits' => ['plan' => 'pro'], 'email' => $user->email]);
```

Events are buffered during the request and flushed **after the response is sent**
(via the app's `terminating` hook), so tracking adds no latency to the response.

## Plain PHP

```php
use Whisperr\Whisperr;

$whisperr = new Whisperr(['api_key' => getenv('WHISPERR_API_KEY')]);

$whisperr->track('user_8842', 'subscription_cancelled', ['reason' => 'card_declined']);

$whisperr->flush(); // also auto-flushes on shutdown
```

The user id (`external_user_id`) is **always explicit** — the server has no
session to infer it from. Pass the same id you use everywhere else for that user,
and frontend + backend events land on one timeline automatically.

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
| `disabled` | `false` | No-op client (useful in tests). |
| `debug` | `false` | Verbose logging via `error_log`. |
| `on_error` | — | `callable(WhisperrError): void` for observability. |

---

Whisperr — predict churn, automate interventions, recover revenue.
[whisperr.net](https://whisperr.net)

## Runtime support

PHP 8.0 or later is supported. The runtime package uses only `ext-curl` and
`ext-json`; Laravel is optional. CI tests PHP 8.0 with Illuminate 8.83 and
PHPUnit 9.6, and PHP 8.1–8.3 with compatible Illuminate 10/11 versions. This
includes service-provider registration and termination behavior.

## Durable backend outcomes

Use `publish()` from a durable outbox worker when losing a committed outcome is
unacceptable. Unlike `track()`, it is synchronous, makes **one HTTP attempt**,
and returns an authoritative ingestion receipt. It requires a server ingestion
key **bound to the approved Whisperr source producer**. A legacy key's generic
2xx response is not accepted as proof of durable source ingestion.

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

The existing `track()`/`flush()` API remains request-buffered. Batch responses must
acknowledge all submitted events before the SDK clears them; partial/invalid
responses retain the batch because the API does not identify rejected items.
Use `publish()` for durable outcomes, where individual receipts matter. Custom
transports can opt in through `PublisherTransportInterface`; older transports
remain compatible with buffered tracking.

## Server-backed message history

A customer backend can proxy message history to its authenticated mobile user:

```php
// Derive this ID from the authenticated server session, never a query parameter.
$externalUserId = (string) $authenticatedUser->id;
$page = $whisperr->messageHistory($externalUserId, 50, $cursor);
$detail = $whisperr->message($externalUserId, $messageId);
```

The list returns `messages` and `next_cursor`; detail returns `message`. Each
message contains `id`, `title`, `body`, `created_at` and `action` (object or null).
Actions remain structured data: the customer app validates supported destinations
and current product/venue visibility before opening them. The server key stays on
the backend. User IDs, message IDs and cursors are URL encoded; redirects are not
followed. History requires Whisperr's server-only user-message API.

Failures throw `Whisperr\RequestException`, exposing `status()` and `errorCode()`
without leaking the key or upstream response body. Malformed responses are errors,
not an empty inbox. Custom transports opt in through
`MessageHistoryTransportInterface`.

When channel registration/revocation must finish before responding, use
`identifyNow($externalUserId, $params)` instead of buffered `identify()`. It makes
one synchronous `/v1/identify` request and returns the verified `user` response,
or throws `RequestException`. Pass explicit channel consent:

```php
$whisperr->identifyNow((string) $authenticatedUser->id, [
    'channels' => [['channel' => 'push', 'address' => $exactToken, 'opted_in' => false]],
]);
```

Use the exact previous token when revoking; never put device tokens into event
properties. The authentication layer must derive the user identity before this
SDK call. Custom transports opt in through `IdentityTransportInterface`.
