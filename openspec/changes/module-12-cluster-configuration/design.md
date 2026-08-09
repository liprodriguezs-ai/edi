# Module 12 — Cluster Configuration — Design Decisions

## Thin module — no domain tables

The deliberate decision is that configuration has no tables of its own. Configuration data
lives in pre-existing tables (`tenant`, `company`, `setting`) whose schemas were established
in earlier modules. Module 12 adds only a controller and routes, keeping migrations empty.
This avoids a fourth table that would partially duplicate the Setting store.

## Plain controller, not Orion

`ConfigurationController` extends `Illuminate\Routing\Controller` (not the Orion base
controller) because it does not represent a single model resource. Its four sections each
read/write to different underlying tables. Using Orion would require four separate controllers
for four separate models, which is more appropriate for CRUD-centric modules; here, the
sections are logically related configuration concerns presented as one surface.

## Confirmation guard for destructive operational changes

Changing `timezone` or `currency` can affect all existing charge due-date displays, payment
receipt timestamps, and cron scheduling. The guard pattern (return 422 with
`requires_confirmation: true` on first attempt, proceed when `confirmed=true` on the second)
is implemented inline in `updateOperational`. This is a two-round-trip pattern that prevents
accidental changes from a programmatic client that doesn't pass all fields intentionally.

The same pattern is documented (but not yet enforced in the controller) for
`bank_data_visible_to_residents` (the docblock notes a `double_confirm` flag). This is marked
as deferred.

## Setting table as key-value store

Collection settings use `Setting::updateOrCreate` keyed on `slug`. The Setting model's
`resolved_value` accessor handles type casting when reading. This means the
collection-settings endpoint does not need to know about the `ChargeStatusService` or
`PaymentService` defaults — it writes the override and the services pick it up via the
`Setting` fallback pattern (already implemented in Collections as of Module 4).

Notification templates use the same Setting table with `notification_template.*` slug prefix.
This avoids creating a dedicated `notification_template` table for what is essentially
structured text configuration.

## Tower-with-units guard: placed in Housing, documented in Configuration

The guard lives in `Housing\TowerController::beforeDestroy`, not in the Configuration module.
This is correct placement — Housing owns Tower, and Orion's `beforeDestroy` hook is the
right extension point. Module 12 documents the spec rule (§16.4 rule 1) and the test
verifies it, but does not duplicate the guard code.

## Tenant::getCustomColumns() as contract

The test asserts that `timezone`, `currency`, `type`, and `uses_ownership_share` appear in
`Tenant::getCustomColumns()`. This is the Stancl Tenancy mechanism for declaring real DB
columns that should not be stored in the `data` JSON blob. The test serves as a regression
guard: if someone removes these from `getCustomColumns`, the operational config endpoint
would silently fail to persist updates.

## Accepted limitations / deferrals

- Timezone/currency change: the confirmation guard is enforced; however, there is no retroactive
  charge timestamp migration — existing charge `issued_at` and `due_date` values are unaffected.
- Billing plan changes: noted in docblock as admin-role-only; Cashier integration not
  implemented in this module.
- Logo/favicon upload: MediaLibrary integration on Company for image assets deferred.
- Audit trail of Setting changes: GlobalObserver covers Tenant and Company mutations; Setting
  changes are not individually audited in v1.
- `bank_data_visible_to_residents` double-confirm guard: docblock mentions it, not enforced.
