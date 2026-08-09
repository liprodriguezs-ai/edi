<?php

namespace QCH\Core\Casts;

use Cknow\Money\Casts\MoneyIntegerCast;
use Cknow\Money\Money;
use Money\Currency;
use QCH\Core\Support\TenantMoney;

/**
 * Tenant-aware money cast. Resolves the currency from the current tenancy
 * context on EVERY call — no static state, no per-tenant default (Octane-safe).
 *
 * Usage: 'amount' => TenantMoneyCast::class   (NEVER append ':MXN')
 * An explicit parameter (e.g. TenantMoneyCast::class.':EUR') still wins via
 * the parent passthrough, preserving vendor semantics.
 */
class TenantMoneyCast extends MoneyIntegerCast
{
    /**
     * @return Currency
     */
    protected function getCurrency(array $attributes) // signature mirrors the vendor: no return type
    {
        if ($this->currency !== null) {
            return parent::getCurrency($attributes); // explicit-param passthrough
        }

        return Money::parseCurrency(TenantMoney::currency());
    }
}
