# Tenant Setting Storage Specification

> Change: `setting-value-native-json` · Capability: `tenant-setting-storage` (NEW) · Phase: `sdd-spec`

## Purpose

`Setting` stores typed per-tenant key-value data (`slug`, `value`, `type`). After this change, `value` is persisted as self-describing JSON; all callers write and read native PHP values through the model. `type` remains authoring/validation metadata and the key that makes the data migration reversible. These requirements make a half-migrated state observable — silent double-encoding is the defining failure mode.

## Requirements

### Requirement: Round-trip fidelity per type

Storing a native PHP value through `Setting::putValue()` or a direct Eloquent write (`create`/`firstOrCreate`/`updateOrCreate`) and reading it back through `Setting::getValue()` MUST yield an identical value AND identical PHP type, for: boolean (`true` AND `false`), integer, string, and array/object.

#### Scenario: Boolean false survives (regression-prone)

- GIVEN `amenity_reservations.enabled` stored with native `false` (type boolean)
- WHEN `Setting::getValue('amenity_reservations.enabled')` runs
- THEN the result is `false` with strict identity (`=== false`), never the string `"false"` or `null`

#### Scenario: Integer

- GIVEN `collections.charge_generation_day` stored with native `15` (type integer)
- WHEN read back via `getValue`
- THEN the result is int `15` (`=== 15`)

#### Scenario: Bare Spanish free text with accents

- GIVEN a string-typed setting stored with `'Notificación de cargos único'`
- WHEN read back
- THEN the identical string returns, accents intact, no added quoting

#### Scenario: Array

- GIVEN `collections.reminders` stored with native array `[3, 1, 0]` (type json)
- WHEN read back
- THEN the identical PHP array `[3, 1, 0]` returns

### Requirement: Pre-serialized values fail loudly

When a write declares a non-string `type` and the supplied value is a string that is itself the serialized form of the declared type (a pre-serialized payload), the write MUST throw an exception and MUST NOT persist anything. This applies to `putValue()` AND direct Eloquent write paths.

#### Scenario: Legacy boolean writer

- GIVEN a caller invokes `putValue('amenity_reservations.enabled', 'true', boolean)`
- WHEN the write executes
- THEN an exception is thrown AND no row is created or updated

#### Scenario: Legacy array writer

- GIVEN a caller passes the string `'[3,1,0]'` for `collections.reminders` (type json)
- WHEN the write executes
- THEN an exception is thrown AND nothing persists

### Requirement: Read contract preserved for existing callers

`Setting::getValue($slug)` MUST return the same native values it returns today for every slug currently in use; call sites doing strict comparisons MUST keep working unmodified.

#### Scenario: Amenity middleware strict gate

- GIVEN `amenity_reservations.enabled` is `true` for the tenant
- WHEN `EnsureAmenityReservationsEnabled` (`Modules/AmenityReservations/app/Http/Middleware/EnsureAmenityReservationsEnabled.php:51`) evaluates `getValue(...) !== true`
- THEN the gate passes; with the flag off or absent, it blocks

#### Scenario: Bank-data visibility strict gate

- GIVEN `collections.bank_data_visible_to_residents` is `true`
- WHEN `ConfigurationController.php:443` evaluates the strict boolean comparison
- THEN bank data is included; with `false` or absent, it is excluded

### Requirement: Absent and null settings

For a slug with no row, `getValue` MUST return `null`. For a row whose stored value is NULL: type boolean MUST resolve to `false`; every other type MUST resolve to `null` (today's behavior — a plain native cast does NOT reproduce the boolean case).

#### Scenario: Missing slug

- GIVEN no `Setting` row exists for a slug
- WHEN `getValue` runs
- THEN it returns `null`

#### Scenario: NULL boolean

- GIVEN a row with type boolean and NULL value
- WHEN `getValue` runs
- THEN it returns `false`, not `null`

### Requirement: Data migration correctness

The one-time data migration MUST: operate cross-tenant (not limited by `TenantScope`); include soft-deleted rows; be idempotent (a second run modifies zero rows); ABORT before writing anything (throwing, with per-type counts and a bounded sample of offending slugs) when ANY row's stored bytes disagree with its declared `type` — never guess, and never half-migrate. Rationale: production runs `php artisan migrate --force` on deploy, so no human inspects the data first; a row left unmigrated decodes to `null`, which `getDelinquencySettings()` silently replaces with a hard-coded default that the next save then persists over the cluster's real customization. Failing the deploy is the safe outcome and removes the need for a manual pre-deploy census; and be reversible via `down()` while `type` is retained.

#### Scenario: Bare string re-encoded

- GIVEN a string-typed row storing bare `R-2026-000123`
- WHEN the migration runs
- THEN the row becomes valid JSON and reads back as the identical string

#### Scenario: Legacy boolean spelling

- GIVEN a boolean-typed row storing legacy `'1'` or `'yes'`
- WHEN the migration runs
- THEN it reads back as native `true`

#### Scenario: Idempotency

- GIVEN the migration already ran
- WHEN it runs again
- THEN zero rows change

#### Scenario: Type/value disagreement aborts the migration

- GIVEN a row with type integer but non-numeric bytes
- WHEN the migration runs
- THEN the row is untouched AND appears in the remediation report

#### Scenario: Soft-deleted and cross-tenant rows included

- GIVEN a soft-deleted row and rows in another tenant
- WHEN the migration runs
- THEN all are migrated

### Requirement: Notification templates payload unchanged

`ConfigurationController::getNotificationTemplates()` MUST return the same payload shape (decoded template structures per `notification_template.*` slug) to the frontend before and after the change.

#### Scenario: Template read parity

- GIVEN seeded `notification_template.*` settings (e.g. `notification_template.charge_created`)
- WHEN `getNotificationTemplates()` responds
- THEN the JSON payload shape and template contents equal the pre-change response

### Requirement: Orion `/setting` resource contract

Create and update through the public `/setting` Orion resource MUST validate and persist native value shapes correctly; `type` MUST remain filterable and sortable.

#### Scenario: Create via API

- GIVEN an authorized request `POST /setting` with a native-shaped value and its `type`
- WHEN the resource stores it
- THEN `getValue` on that slug returns the value with correct native type

#### Scenario: Filter by type

- GIVEN existing settings of mixed types
- WHEN `POST /setting/search` filters or sorts by `type`
- THEN it behaves as before the change
