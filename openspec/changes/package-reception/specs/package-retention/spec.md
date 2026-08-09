# Package Retention Specification

## Purpose

Defines the daily per-tenant background job that reminds residents about uncollected packages
and flags packages overdue for return. The job follows the cron mold established by
`RefreshChargeStatusesCommand` in the Collections module.

---

## Requirements

### Requirement: `package:process-retention` Artisan command

A new Artisan command `package:process-retention` MUST be created in
`backend/Modules/Package/app/Console/Commands/ProcessPackageRetentionCommand.php`.

The command MUST:

1. Iterate over all tenants using `Tenant::all()->run(fn ($tenant) => ...)`, matching the
   pattern used by `RefreshChargeStatusesCommand`.
2. Within each tenant context, query all packages with status `ready`.
3. For each ready package, compare `received_at` against the current date (UTC, converted to
   the tenant's `timezone` field):
   - If `(now - received_at) >= reminder_after_days` AND `notified_at` is NOT null (i.e., the
     arrival notification was sent) AND no reminder has been sent yet (see reminder tracking
     below): dispatch a `package.reminder` notification to the active residents of the unit.
   - If `(now - received_at) >= return_after_days`: flag the package by logging a warning and
     optionally transitioning status to `returned` (see note below).

**Reminder tracking:** To avoid sending duplicate reminders, a `reminded_at` datetime column
MUST be added to the `package` table. The command sets `reminded_at = now()` after dispatching
a reminder. On subsequent runs, packages where `reminded_at IS NOT NULL` are skipped for the
reminder step (reminder is sent once).

**Return action:** After `return_after_days` have elapsed, the command MUST call
`PackageService::returnPackage($package, 'Plazo de retención expirado')` to transition the
status to `returned`. This is the system-initiated return path. The policy
`package.return_after_days` is read from `Setting` at runtime per tenant.

#### Scenario: Reminder sent after reminder_after_days

- GIVEN a package with status `ready`, `received_at = 3 days ago`, `reminded_at = null`
- AND `package.reminder_after_days = 3` for the tenant
- WHEN `package:process-retention` runs
- THEN a `package.reminder` notification is dispatched to the active residents of the package's unit
- AND `package.reminded_at` is set to a non-null datetime

#### Scenario: Duplicate reminder is not sent

- GIVEN a package with status `ready`, `received_at = 5 days ago`, `reminded_at = 3 days ago`
- AND `package.reminder_after_days = 3`
- WHEN `package:process-retention` runs
- THEN NO `package.reminder` notification is dispatched for this package

#### Scenario: Package returned after return_after_days

- GIVEN a package with status `ready`, `received_at = 11 days ago`
- AND `package.return_after_days = 10` for the tenant
- WHEN `package:process-retention` runs
- THEN `PackageService::returnPackage` is called for this package
- AND `package.package_status_id = PackageStatus::RETURNED`
- AND `package.incident_reason = 'Plazo de retención expirado'`

#### Scenario: Picked-up packages are skipped

- GIVEN a package with status `picked_up` and `received_at = 30 days ago`
- WHEN `package:process-retention` runs
- THEN no reminder or return action is taken for this package

---

### Requirement: Command is scheduled daily per tenant

The command MUST be registered in the `PackageServiceProvider` (or equivalent service provider)
within the `Package` module's `registerCommandSchedules` method, following the pattern of
`CollectionsServiceProvider::registerCommandSchedules`.

The schedule MUST run the command **daily** (e.g., `->dailyAt('08:00')`). The exact time is a
design decision for `sdd-design`; the spec requires daily frequency.

#### Scenario: Command is registered in the scheduler

- GIVEN `php artisan schedule:list` is run
- WHEN the output is inspected
- THEN `package:process-retention` appears with a daily frequency

---

### Requirement: `package.reminder` notification payload

The `package.reminder` notification MUST be dispatched via `NotifiesResidents` (same mechanism
as the arrival notification). It MUST include:

- Package identifier (carrier name or `carrier_other` or "Paquete sin especificar").
- `received_at` formatted in the tenant's timezone.
- Number of days since arrival.
- `click_action: '/my-packages'`.
- A message informing the resident that the package will be returned if not collected before
  `return_after_days - elapsed_days` days.

#### Scenario: Reminder notification reaches active residents

- GIVEN a package for unit U with 2 active residents (users A and B)
- WHEN the reminder criteria are met
- THEN both user A and user B receive a `package.reminder` notification event

---

### Requirement: `reminded_at` column added to `package` table

The `package` migration (or a separate additive migration) MUST include:

```
$table->datetime('reminded_at')->nullable()->comment('Timestamp when the retention reminder was sent');
```

The column MUST default to null. The retention command sets it after dispatching the reminder.

#### Scenario: reminded_at is null for newly registered packages

- GIVEN a package is registered via `PackageService::register`
- WHEN the package row is inspected immediately after creation
- THEN `reminded_at` is null

---

### Requirement: Command is tenant-safe and does not cross tenant boundaries

The command MUST use `Tenant::all()->run(fn)` so that each iteration runs within the correct
tenant context (`TenantScope` applied). Direct `DB::table('package')` queries without tenant
scope MUST NOT appear in the command.

#### Scenario: Tenant B packages are not affected by Tenant A's retention run

- GIVEN Tenant A has a package overdue for return, Tenant B has a package within retention
- WHEN `package:process-retention` runs globally
- THEN only Tenant A's overdue package is returned
- AND Tenant B's package remains in `ready` status
