---
name: package-wu4-retention-cron
description: WU-4 Package retention cron tenancy audit — SAFE; canonical Tenant::all()+$tenant->run() per-tenant isolation, no cross-tenant mutation/leak
metadata:
  type: pattern
---

# Package WU-4 — Retention cron (ProcessPackageRetentionCommand) — SAFE

Reviewed 2026-06-29 (branch feat/package-reception). Verdict: SHIP, isolation SAFE, zero findings.

Scope: `Modules/Package/app/Console/Commands/ProcessPackageRetentionCommand.php`,
`PackageServiceProvider.php` (schedule), `tests/Feature/Package/PackageRetentionTest.php`.

Why SAFE (the per-tenant cron isolation chain, all verified):
- `Tenant::all()` is central (Tenant extends Stancl BaseTenant, no TenantScope) → returns ALL tenants. Correct for a cross-tenant cron.
- Implemented as `foreach (Tenant::all() as $t) { $t->run(closure) }` — matches canonical `RefreshChargeStatusesCommand` (Collections). Task pseudocode said `Tenant::all()->run()` but a Collection has no run(); the foreach impl is the correct one.
- Stancl `TenantRun::run()` (vendor/.../Database/Concerns/TenantRun.php): saves prior tenant (null in console), `tenancy()->initialize($this)`, runs callback, then reverts/`end()`. Atomic per-iteration isolation — no leakage between tenants.
- Per-tenant Setting reads: `Setting::getValue($slug)` = `static::query()->where('slug',$slug)->first()?->resolved_value`. Setting uses `BelongsToTenant` and there is NO static/per-slug memoization → in a single long-running cron process each tenant gets ITS OWN value (no cross-tenant cache bleed). Defaults applied via `?? 3 / ?? 10`.
- No cross-tenant package mutation: `Package::where('package_status_id', READY)->get()` is TenantScope-scoped; `returnPackage()` (status-guarded READY) + `saveQuietly()` for reminded_at mutate only this tenant's rows (UPDATE by PK, tenant_id already correct).
- Reminders only to that tenant's residents: `dispatchReminder` → `unitResidentUserIds($package->unit_id)` (tenant-scoped UnitUser via NotifiesResidents). Even with a corrupt cross-tenant unit_id, the UnitUser query is tenant-scoped so foreign residents can never be resolved. `notifyUsers` records tenant-scoped NotificationUser via `DB::afterCommit` (runs immediately — cron has no open txn).
- Schedule fires ONCE globally (`->dailyAt('08:00')`); per-tenant iteration is internal to the command. Correct (do NOT expect a per-tenant schedule). Tenant timezone is used only for diffInDays/display, not for WHEN the cron runs — design choice, not an isolation issue.
- Isolation test `test_tenant_a_overdue_returned_while_tenant_b_within_retention_stays_ready` is genuine: NotificationUser + Package both `BelongsToTenant`, asserts A→RETURNED and B→READY after one global run.

Non-tenancy (out of scope, not reported): if a READY package's unit has no active residents, `dispatchReminder` returns early but caller still stamps `reminded_at` + increments the `reminded` counter (cosmetically misleading). returnPackage uses loud `save()` (audits w/ null actor) vs reminder's `saveQuietly()` — minor inconsistency, not isolation.
