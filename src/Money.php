<?php

declare(strict_types=1);

namespace Whisperr;

/** ISO 4217 minor units for catalog prices. */
final class Money
{
    /** The ISO 4217 minor units that are not 2. The same table as the Whisperr server. */
    private const MINOR_DIGITS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0,
        'RWF' => 0, 'UGX' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'CLF' => 4, 'UYW' => 4,
    ];

    /** The number of minor-unit digits for an ISO 4217 code (2 unless listed). */
    public static function currencyExponent(string $currency): int
    {
        if (!preg_match('/^[A-Za-z]{3}$/D', $currency)) {
            throw new \InvalidArgumentException("invalid currency \"$currency\": expected an ISO 4217 code");
        }
        return self::MINOR_DIGITS[strtoupper($currency)] ?? 2;
    }

    /**
     * Converts a price in major units to integer minor units:
     * toMinorUnits('15.00', 'USD') is 1500, toMinorUnits(1500, 'JPY') is 1500,
     * toMinorUnits('1.234', 'KWD') is 1234.
     *
     * Pass a string when you have one (it is exact). A float is read through
     * its shortest decimal form, so 19.99 is 1999. An amount with more
     * precision than the currency has ('9.999' USD), a negative amount, or a
     * non-number throws InvalidArgumentException: money is never rounded.
     *
     * @param string|int|float $amount
     */
    public static function toMinorUnits($amount, string $currency): int
    {
        $exponent = self::currencyExponent($currency);
        if (is_int($amount)) {
            $text = (string) $amount;
        } elseif (is_float($amount)) {
            if (!is_finite($amount)) {
                throw self::invalid($amount);
            }
            // serialize_precision -1: the shortest form that reads back the same.
            $text = json_encode($amount, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_string($amount)) {
            $text = trim($amount);
        } else {
            throw self::invalid($amount);
        }
        if (!preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D', $text, $m)) {
            throw self::invalid($amount);
        }
        $fraction = $m[2] ?? '';
        $scale = $exponent + (int) ($m[3] ?? 0) - strlen($fraction);
        $digits = ltrim($m[1] . $fraction, '0');
        if ($scale >= 0) {
            $digits .= $digits === '' ? '' : str_repeat('0', $scale);
        } else {
            $cut = strlen($digits) + $scale;
            $dropped = $cut > 0 ? substr($digits, $cut) : $digits;
            if (preg_match('/[1-9]/', $dropped)) {
                throw self::invalid($amount);
            }
            $digits = $cut > 0 ? substr($digits, 0, $cut) : '';
        }
        $digits = $digits === '' ? '0' : $digits;
        // At most 2^53 - 1, so the value is exact in every Whisperr SDK.
        if (strlen($digits) > 16 || (strlen($digits) === 16 && strcmp($digits, '9007199254740991') > 0)) {
            throw self::invalid($amount);
        }
        return (int) $digits;
    }

    /** @param mixed $amount */
    private static function invalid($amount): \InvalidArgumentException
    {
        $shown = is_float($amount) ? var_export($amount, true) : (is_scalar($amount) ? (string) $amount : gettype($amount));
        return new \InvalidArgumentException(
            "invalid amount \"$shown\": expected a non-negative decimal with at most the currency's minor digits"
        );
    }
}
