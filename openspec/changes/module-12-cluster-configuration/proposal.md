# Module 12 — Cluster Configuration (Configuración del Clúster)

## Intent

Provide a single, structured surface for operators and board members to configure the
operational parameters of their residential cluster. Rather than introducing new domain
tables, this thin module routes configuration reads and writes to existing tables: `tenant`
(operational columns), `company` (legal/branding identity), and `setting` (key-value tenant
store for collection thresholds and notification templates).

## Scope

New thin module `Configuration` (`backend/Modules/Configuration`):

- **No new domain tables** (no migrations in this module).
- **ConfigurationController**: four logical sections mapped to REST endpoints:
  - `GET/PUT config/general` — company legal/branding (name, legal_name, rfc,
    fiscal_address, color_primary, color_secondary).
  - `GET/PUT config/operational` — tenant operational columns (timezone, currency, type,
    uses_ownership_share); timezone/currency change requires explicit `confirmed=true` flag.
  - `GET/PUT config/collection-settings` — collections.* slugs in the Setting table
    (charge_generation_day, default_due_day, default_grace_days, due_soon_days,
    overpayment_pct, reminders, physical_receipt).
  - `GET/PUT config/notification-templates` — notification_template.* slugs in the
    Setting table (arbitrary key-value template strings).
- **Tower-with-units delete guard**: enforced in `Housing\TowerController::beforeDestroy`
  via `UnprocessableEntityHttpException` when `Tower.hasUnits()` is true. Documented here
  as a configuration-level protection (spec §16.4 rule 1).
- Menu/permission registration, integration tests (6 scenarios).

## Approach

- The controller is a plain `Illuminate\Routing\Controller` (not an Orion controller) since
  it does not manage a single model resource.
- Writes to tenant columns use `Tenant::findOrFail(tenant()->getTenantKey())` and `fill` +
  `save`, following the same pattern as any Eloquent update.
- Writes to `Setting` use `updateOrCreate` keyed on `slug`, with automatic type detection
  (boolean/json/integer/string).
- Confirmation guard for timezone/currency: if `timezone` or `currency` is present in the
  payload but `confirmed=true` is not sent, the endpoint returns HTTP 422 with
  `requires_confirmation: true`. The frontend must present a warning modal before resubmitting.

## Out of Scope (v1)

- Billing plan change / Stripe subscription modification (Cashier integration deferred;
  noted as admin-role-only in docblock).
- Theme/branding media upload (logo, favicon) via MediaLibrary (deferred).
- Per-user notification preference configuration.
- Audit trail of configuration changes (the GlobalObserver covers model-level changes on
  `Tenant` and `Company`; `Setting` changes are not individually audited in v1).
