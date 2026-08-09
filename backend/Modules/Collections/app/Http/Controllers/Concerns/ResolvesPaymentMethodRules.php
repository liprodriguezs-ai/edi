<?php

namespace Modules\Collections\Http\Controllers\Concerns;

use Modules\Collections\Models\PaymentMethod;
use Modules\Setting\Models\Setting;

/**
 * Shared payment-method gate rules for the money endpoints (payment/register,
 * payment/submit and charge/direct). Kept in ONE place so the comprobante rule
 * and the cluster-config enable/disable check never drift between controllers.
 */
trait ResolvesPaymentMethodRules
{
    /**
     * Whether the given payment method requires a comprobante (anything but cash, §8.7.1 r5).
     */
    protected function requiresReceipt(string $paymentMethodId): bool
    {
        return $paymentMethodId !== PaymentMethod::CASH;
    }

    /**
     * Whether the given payment method is enabled in cluster configuration (§16.3.4).
     *
     * The enabled set lives in the tenant-scoped Setting
     * `collections.payment_methods_enabled` (a JSON array of method UUIDs). When
     * the setting is ABSENT or empty, every method is allowed (backwards-compatible
     * default — no cluster has configured restrictions yet).
     */
    protected function paymentMethodIsEnabled(string $paymentMethodId): bool
    {
        $enabled = Setting::getValue('collections.payment_methods_enabled');

        if (! is_array($enabled) || $enabled === []) {
            return true;
        }

        return in_array($paymentMethodId, $enabled, true);
    }
}
