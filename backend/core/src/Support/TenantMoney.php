<?php

namespace QCH\Core\Support;

use Cknow\Money\Money;

/**
 * Single conversion point between raw minor-unit integers and Money at the
 * response boundary. Aggregates and cache payloads stay plain ints; wrap with
 * make() when building JSON, and use format() for notification/PDF strings.
 * Currency resolves per call from tenancy — never cache it in a static.
 */
class TenantMoney
{
    /** Active currency code: tenant currency when tenancy is initialized, else central fallback. */
    public static function currency(): string
    {
        if (tenancy()->initialized) {
            $currency = (string) (tenant()->currency ?? '');

            if ($currency !== '' && Money::isValidCurrency($currency)) {
                return $currency;
            }
        }

        return (string) config('money.defaultCurrency', 'MXN');
    }

    /** Wrap raw minor units — serializes to {amount, currency, formatted} like the model casts. */
    public static function make(int $minorUnits): Money
    {
        return money($minorUnits, self::currency());
    }

    /** Display string for notifications/PDF (replaces '$'.number_format($raw/100, 2).' MXN'). */
    public static function format(int $minorUnits): string
    {
        return self::make($minorUnits)->format();
    }
}
