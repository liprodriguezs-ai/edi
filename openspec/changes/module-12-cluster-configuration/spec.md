# Module 12 — Cluster Configuration — Spec

## Requirements

### R1 — General configuration (company)
`GET /api/config/general` returns the company record where `main = true`.
`PUT /api/config/general` accepts and validates: `name`, `legal_name`, `rfc`,
`fiscal_address`, `color_primary`, `color_secondary` (all `sometimes|string|max:N`).
Updates and saves the main company record.

### R2 — Operational configuration (tenant)
`GET /api/config/operational` returns `{timezone, currency, type, uses_ownership_share}`
from the current tenant record.
`PUT /api/config/operational` accepts: `timezone` (string, timezone), `currency` (string),
`type` (string), `uses_ownership_share` (boolean), `confirmed` (boolean).

**Confirmation guard** (spec §16.4 rule 2): if `timezone` or `currency` is present and
`confirmed` is not `true`, returns HTTP 422 with `{message, requires_confirmation: true}`.
The frontend must send `confirmed=true` explicitly after presenting a warning to the operator.

### R3 — Collection settings (Setting table)
`GET /api/config/collection-settings` returns resolved values for the following Setting slugs:
`collections.charge_generation_day`, `collections.default_due_day`,
`collections.default_grace_days`, `collections.due_soon_days`,
`collections.overpayment_pct`, `collections.reminders`, `collections.physical_receipt`.

`PUT /api/config/collection-settings` accepts these keys (all `sometimes`), validates types
(integer 1–28 for day fields, integer 0–30 for grace/due_soon, numeric for pct, array for
reminders, boolean for physical_receipt), and upserts via `Setting::updateOrCreate` keyed on
`slug`. Type is auto-detected (boolean/json/integer/string).

### R4 — Notification templates (Setting table)
`GET /api/config/notification-templates` returns all Setting rows where `slug LIKE
'notification_template.%'`.

`PUT /api/config/notification-templates` accepts `templates[]` array of
`{slug (starts_with:notification_template.), value (string)}` and upserts each into the
Setting table with `type = string`.

### R5 — Tower-with-units delete guard
`Tower.hasUnits()` returns `true` if the tower has at least one non-deleted unit assigned.
`TowerController::beforeDestroy` throws `UnprocessableEntityHttpException` if `hasUnits()`.
This prevents orphaning unit records when a tower configuration is removed.

### R6 — Tenant operational columns as real columns
`timezone`, `currency`, `type`, `uses_ownership_share` are real DB columns on the `tenant`
table (listed in `Tenant::getCustomColumns()`), not JSON-packed into the Stancl `data` column.
Updates persist via standard Eloquent `fill` + `save`.

## Scenarios (acceptance — from tests in ConfigurationModuleTest.php)

- **A. Tenant columns are real**: `Tenant::getCustomColumns()` includes timezone, currency,
  type, uses_ownership_share.
- **B. Tenant operational update persists**: set timezone='America/Monterrey', currency='USD',
  uses_ownership_share=true → reloaded tenant has the correct values.
- **C. Collection settings persist in Setting table**: `updateOrCreate` for
  `collections.due_soon_days` (value '7') and `collections.overpayment_pct` (value '1.5')
  → readable back from the Setting table with correct values.
- **D. Collection settings are tenant-scoped**: setting written in tenant A not visible in
  tenant B (BelongsToTenant on Setting model).
- **E. Tower without units is deletable**: `Tower.hasUnits() = false` → delete succeeds.
- **F. Tower with units throws**: `Tower.hasUnits() = true` → `UnprocessableEntityHttpException`
  is thrown by the guard.
- **G. Company general data update**: create company, update `legal_name` and `color_primary`
  → reloaded company has the updated values.
