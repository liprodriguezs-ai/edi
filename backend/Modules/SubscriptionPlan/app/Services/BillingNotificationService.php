<?php

namespace Modules\SubscriptionPlan\Services;

use App\Notifications\Concerns\DispatchesNotifications;
use App\Notifications\NotificationCategory;
use Modules\SubscriptionPlan\Models\BillableModule;

/**
 * BillingNotificationService — in-app/push notifications for billing lifecycle events.
 *
 * Uses the DispatchesNotifications trait so every dispatch goes through the standard
 * notification engine (per-event channel config, in-app rows, push, user preferences).
 *
 * TENANT CONTEXT REQUIRED: all public methods on this service must be called
 * inside a tenant context ($tenant->run() or after tenancy()->initialize()).
 * Without it, userIdsWithRole() logs a warning and returns no recipients.
 *
 * DISPATCH SAFETY: notifyEvent() defers via DB::afterCommit, so calling this
 * service after a domain write (but outside a transaction) is always safe.
 */
final class BillingNotificationService
{
    use DispatchesNotifications;

    /**
     * Notify the organization administrator(s) that a module trial has expired.
     *
     * Sent once per expired trial entitlement, from the
     * billing:expire-manual-entitlements cron. The body prompts the admin to
     * add the module to the organization's subscription to avoid losing access.
     *
     * Must be called inside a tenant context ($tenant->run()).
     */
    public function notifyTrialExpired(BillableModule $module): void
    {
        $adminIds = $this->userIdsWithRole('administrator');

        if ($adminIds === []) {
            return;
        }

        $moduleName = $module->name;
        $context = ['module' => $moduleName];

        $this->notifyEvent(
            'trial_expired',
            $adminIds,
            $context,
            'Prueba de {module} vencida',
            'La prueba de {module} venció. Contrata el plan del organización para no perder el acceso.',
            ['category' => NotificationCategory::SYSTEM],
        );
    }
}
