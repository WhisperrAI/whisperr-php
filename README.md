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
