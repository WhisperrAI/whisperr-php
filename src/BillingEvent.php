<?php

declare(strict_types=1);

namespace Whisperr;

/**
 * Reserved billing fallback event names and their money properties (spec C6,
 * from the playbook engine spec section 3.8).
 *
 * A backend sends them with track() or publish(), never the front end. Until
 * the server accepts these names without registration, the app must register
 * them (the Whisperr MCP does it).
 *
 *     $whisperr->track($userId, BillingEvent::RENEWAL_SUCCEEDED, BillingEvent::properties(
 *         planId: 'pro_monthly', price: 9.99, currency: 'usd', planPeriod: 'P1M',
 *         transactionId: 'txn_123', store: 'stripe',
 *     ), messageId: 'renewal_123', occurredAt: '2026-10-12T08:00:00Z');
 */
final class BillingEvent
{
    public const TRIAL_STARTED = 'trial_started';
    public const TRIAL_CONVERTED = 'trial_converted';
    public const TRIAL_AUTO_RENEW_DISABLED = 'trial_auto_renew_disabled';
    public const MEMBERSHIP_STARTED = 'membership_started';
    public const RENEWAL_SUCCEEDED = 'renewal_succeeded';
    public const AUTO_RENEW_DISABLED = 'auto_renew_disabled';
    public const AUTO_RENEW_ENABLED = 'auto_renew_enabled';
    public const MEMBERSHIP_ENDED = 'membership_ended';
    public const BILLING_ISSUE_STARTED = 'billing_issue_started';
    public const BILLING_RECOVERED = 'billing_recovered';
    public const REFUND_GRANTED = 'refund_granted';

    public const ALL = [
        self::TRIAL_STARTED,
        self::TRIAL_CONVERTED,
        self::TRIAL_AUTO_RENEW_DISABLED,
        self::MEMBERSHIP_STARTED,
        self::RENEWAL_SUCCEEDED,
        self::AUTO_RENEW_DISABLED,
        self::AUTO_RENEW_ENABLED,
        self::MEMBERSHIP_ENDED,
        self::BILLING_ISSUE_STARTED,
        self::BILLING_RECOVERED,
        self::REFUND_GRANTED,
    ];

    private function __construct()
    {
    }

    /**
     * Build the money properties for a billing event. A null argument adds no key.
     * Typed arguments win over keys in $extra.
     *
     * @param int|float|null $price major units (9.99, not 999 cents); sent as a float
     * @param string|null $currency ISO 4217; upper-cased ('gel' becomes 'GEL')
     * @param string|null $planPeriod ISO 8601 duration, for example 'P1M'
     * @param array<string,mixed> $extra event properties from spec 3.8, for example current_period_ends_at
     * @return array<string,mixed>
     * @throws \InvalidArgumentException when price is negative or not finite, or currency is not three letters
     */
    public static function properties(
        ?string $planId = null,
        int|float|null $price = null,
        ?string $currency = null,
        ?string $planPeriod = null,
        ?string $transactionId = null,
        ?string $store = null,
        array $extra = []
    ): array {
        $typed = [];
        if ($planId !== null) {
            $typed['plan_id'] = $planId;
        }
        if ($price !== null) {
            if (!is_finite((float) $price) || $price < 0) {
                throw new \InvalidArgumentException('price must be a finite number >= 0 in major units, for example 9.99');
            }
            $typed['price'] = (float) $price;
        }
        if ($currency !== null) {
            $currency = strtoupper(trim($currency));
            if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw new \InvalidArgumentException('currency must be an ISO 4217 code, for example "USD"');
            }
            $typed['currency'] = $currency;
        }
        if ($planPeriod !== null) {
            $typed['plan_period'] = $planPeriod;
        }
        if ($transactionId !== null) {
            $typed['transaction_id'] = $transactionId;
        }
        if ($store !== null) {
            $typed['store'] = $store;
        }
        return array_merge($extra, $typed);
    }
}
