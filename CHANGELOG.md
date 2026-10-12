# Changelog

Packagist reads versions from git tags (`vX.Y.Z`). `Whisperr::VERSION` must
match the newest entry here; a test checks it.

## 0.3.0 - unreleased

Whisperr spec contract v2 for server SDKs.

### Added

- Every event (`track()` and `publish()`) carries `context.sdk_name`
  (`whisperr-php`) and `context.sdk_version` (`Whisperr::VERSION`).
- New option `app_version` (Laravel: `WHISPERR_APP_VERSION`). When set, every
  event carries `context.app_version`. When it is empty, the key is not sent.
  A key that the caller puts in `$context` for one event wins.
- `Whisperr\BillingEvent`: constants for the reserved billing fallback names
  (`trial_started`, `subscription_started`, `subscription_renewed`,
  `payment_failed`, `subscription_cancelled`, `purchase_completed`) and
  `BillingEvent::properties()` for `product_id`, `price` (major units),
  `currency` (ISO 4217, upper-cased), `period`, `transaction_id`, `store`.
  It throws `InvalidArgumentException` for a currency that is not three
  letters, or a price that is negative or not finite.
- README: the `app_language` trait and the billing fallback events.
- Tests run the server sections of whisperr-spec `conformance/v2.json`.

## 0.2.1 - 2026-10-12

- `track()` takes optional `messageId` and `occurredAt` arguments for
  idempotent server events. An invalid value drops the event; `track()` never
  throws.

## 0.2.0 - 2026-10-12

- PHP 8.0+ and Laravel 8 (Illuminate ^8.83) through 11.
- `publish()` for durable outbox workers: one HTTP attempt with a caller event
  ID and the original `occurred_at`, returns a `PublishResult` receipt.
- Any 2xx batch response dequeues the batch. An unencodable payload is dropped
  with a warning and does not throw from `flush()`.

## 0.1.1 - 2026-06-16

- Drop an invalid `event_type` before it is queued. Identify accepts `channel`
  or `type` on explicit channels.

## 0.1.0 - 2026-06-15

- First release: buffered `track()` / `identify()`, retry with backoff,
  Laravel service provider and facade.
