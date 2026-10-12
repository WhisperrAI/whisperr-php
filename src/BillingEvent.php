<?php

declare(strict_types=1);

namespace Whisperr;

/**
 * Reserved billing fallback event names and their properties (spec C6).
 *
 * Send them with track() or publish(). Until the server accepts these names
 * without registration, the app must register them (the Whisperr MCP does it).
 *
 *     $whisperr->track($userId, BillingEvent::SUBSCRIPTION_RENEWED, BillingEvent::properties(
 *         productId: 'pro_monthly', price: 9.99, currency: 'usd', period: 'P1M',
 *         transactionId: 'txn_123', store: 'stripe',
 *     ));
 */
final class BillingEvent
{
    public const TRIAL_STARTED = 'trial_started';
    public const SUBSCRIPTION_STARTED = 'subscription_started';
    public const SUBSCRIPTION_RENEWED = 'subscription_renewed';
    public const PAYMENT_FAILED = 'payment_failed';
    public const SUBSCRIPTION_CANCELLED = 'subscription_cancelled';
    public const PURCHASE_COMPLETED = 'purchase_completed';

    public const ALL = [
        self::TRIAL_STARTED,
        self::SUBSCRIPTION_STARTED,
        self::SUBSCRIPTION_RENEWED,
        self::PAYMENT_FAILED,
        self::SUBSCRIPTION_CANCELLED,
        self::PURCHASE_COMPLETED,
    ];

    private function __construct()
    {
    }

    /**
     * Build the reserved billing properties. A null argument adds no key.
     * Typed arguments win over keys in $extra.
     *
     * @param int|float|null $price major units (9.99, not 999 cents); sent as a float
     * @param string|null $currency ISO 4217; upper-cased ('gel' becomes 'GEL')
     * @param string|null $period ISO 8601 duration, for example 'P1M'
     * @param array<string,mixed> $extra more properties for the same event
     * @return array<string,mixed>
     * @throws \InvalidArgumentException when price is not finite or currency is not three letters
     */
    public static function properties(
        ?string $productId = null,
        int|float|null $price = null,
        ?string $currency = null,
        ?string $period = null,
        ?string $transactionId = null,
        ?string $store = null,
        array $extra = []
    ): array {
        $typed = [];
        if ($productId !== null) {
            $typed['product_id'] = $productId;
        }
        if ($price !== null) {
            if (!is_finite((float) $price)) {
                throw new \InvalidArgumentException('price must be a finite number in major units, for example 9.99');
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
        if ($period !== null) {
            $typed['period'] = $period;
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
