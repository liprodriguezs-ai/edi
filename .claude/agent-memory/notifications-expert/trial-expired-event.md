---
name: trial-expired-event
description: NotificationEvent TRIAL_EXPIRED — added 2026-07-01. Dispatched from billing:expire-manual-entitlements cron when a SOURCE_TRIAL entitlement lapses. Targets administrator role. BillingNotificationService with NotifiesResidents.
metadata:
  type: project
---

## Event: TRIAL_EXPIRED

- **UUID const:** `NotificationEvent::TRIAL_EXPIRED = '2f3a4b5c-6d7e-4f8a-9b0c-1d2e3f4a5b6c'`
- **Key slug:** `trial_expired`
- **Audience:** `board` (closest fit; the administrator role is not a resident or a board member, but board fits better than resident for UI purposes)
- **Category:** `NotificationCategory::SYSTEM`
- **Default channels:** `['log', 'firebase_push']` (in-app always; push if configured)
- **Supports email:** false — push/in-app only as requested by task
- **Order:** 11 (after payment_registered_direct)
- **Seeded by:** `CatalogSeeder::seedNotificationEvents()` — auto-picked up by `TenantNotificationSettingSeeder` (loops over `NotificationEvent::all()`, no special case needed)

## Dispatch location

`Modules/SubscriptionPlan/app/Console/Commands/ExpireManualEntitlementsCommand.php::handle()`

- Fires AFTER `EntitlementService::recompute($tenant)` and BEFORE the `$hasAnyActiveEntitlement` block
- Filtered to `source = SOURCE_TRIAL` only (SOURCE_MANUAL and SOURCE_SUPER_ADMIN_FREE don't notify)
- Runs inside `$tenant->run(fn() => ...)` for tenant context
- One notification per expired trial entitlement (in case multiple trials expire the same day)

## Service

`Modules/SubscriptionPlan/app/Services/BillingNotificationService.php`

- Uses `NotifiesResidents` trait
- `notifyTrialExpired(BillableModule $module): void`
- Recipients: `userIdsWithRole('administrator')` — returns early if no admins found
- Context: `['module' => $module->name]`
- Fallback title: `'Prueba de {module} vencida'` (module name substituted)
- Fallback body: `'La prueba de {module} venció. Inclúyela en tu cobro para no perder el acceso.'`

## Tests

`tests/Feature/Notifications/TrialExpiredNotificationTest.php`

3 tests:
1. `test_cron_notifies_administrator_when_trial_expires` — happy path (cluster blocked)
2. `test_administrator_is_notified_even_when_cluster_stays_active` — partial-lapse scenario (another entitlement keeps cluster up; trial still notifies)
3. `test_cron_does_not_notify_for_expired_manual_source` — SOURCE_MANUAL does NOT trigger trial_expired notification

## Pre-existing suite issue (NOT introduced by this change)

`NotificationCenterScopingTest` fails when the FULL `tests/Feature/Notifications/` dir runs in sequence (FK violation on `notification_user.user_id` from prior test's cleaned user). Passes in isolation. Pre-existing ordering bug, not caused by trial_expired work.

## Design notes

- `audience: 'board'` is cosmetic here — `boardRecipientsForEvent` is NOT used; recipients are resolved directly via `userIdsWithRole('administrator')` in the service
- No `notification_setting_board_role` rows needed for this event (no board role pivot)
- Pre-aviso (N days before expiry) NOT implemented — requires new scheduled infra; logged as pending in task
