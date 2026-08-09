---
name: package-module-findings
description: All infra-cost findings for the Package module (WU-1 through WU-4), plus confirmed-clean notes
metadata:
  type: project
---

# Package Module — Cost Findings (WU-1 → WU-4, 2026-06-29)

## Core pattern to know first
`Setting::getValue(slug)` is an UNCACHED raw Eloquent query every call. Project convention is
always batch with `Setting::whereIn('slug', [...])->pluck('value','slug')` — one query per
batch instead of N queries per slug.

---

## WU-1 — Backend Spine

**CRITICAL: Setting::getValue uncached — 2N queries in batchRegister**
`PackageService::register()` calls `getSetting()` twice (pickup_code_enabled, auto_notify).
`batchRegister` calls `register()` per row → 2N Setting queries for N packages.
Fix: `$this->settingCache[$slug] ??= Setting::getValue($slug)` memoized on the service instance.
Status: **FIXED in WU-2** — `PackageService` now has `getSetting()` with per-instance memo.

**WARNING: Double UnitUser query per resident list request**
`buildIndexFetchQuery` → `scopeToResidentUnitsIfNeeded` + `afterIndex` → `revealPickupCodeForUnitMembers`
both query `unit_user WHERE user_id=? AND end_date IS NULL` separately.
Status: **FIXED in WU-2** — `$this->callerActiveUnitIds ??=` memoizes across both call sites.

**Confirmed CLEAN in WU-1**
- `revealPickupCodeForUnitMembers` batches unit membership check (one query for all unit IDs in the page).
- `generateUniquePickupCode` loop bounded ≤10; partial unique index covers the exists() check.
- Orion `includes()` is opt-in (client must request) — NOT auto-loaded.
- `notifyUsers` defers via `DB::afterCommit` — notification failure cannot roll back domain writes.

---

## WU-2 — Pickup & Custody

**WARNING: verifyPickupCode over-fetches `carrier` + `status` on matched package**
`->with(['unit.unitType', 'unit.tower', 'carrier', 'status'])` — `carrier` and `status` on `$found`
are never read. Terminal-status check uses `$found->package_status_id` (raw FK). Only
`unit.unitType` and `unit.tower` are needed (they feed `Unit::full_identifier`).
Fix: `->with(['unit.unitType', 'unit.tower'])` on the first lookup.
Fires on EVERY QR scan × every guard × every tenant → 2 extra SELECTs per scan.

**WARNING: handover — N individual save() calls, no max bound on package_ids**
`foreach ($packages as $pkg) { $pkg->save(); }` = N UPDATEs. `PackagePickupRequest` has no `max:`
on `package_ids`. Fix: add `'package_ids' => ['required','array','min:1','max:20']`.
Per-package save() is intentional for GlobalObserver audit stamping — do not batch with whereIn->update().

**SUGGESTION: myPackages — unbounded get() with 5 eager-loaded relations**
`Package::whereIn('unit_id', $unitIds)->with(['unit','status','carrier','type','media'])->get()` —
no limit. `media` is the heaviest sub-load. Add `->limit(50)` or paginate.

**Confirmed CLEAN in WU-2**
- `getSetting()` memoized on service instance — no raw Setting::getValue in WU-2 methods.
- `myPackages` resident scope: ONE UnitUser query regardless of unit count.
- TenantCatalogSeeder `seedPackageSettings`: `firstOrCreate` × 7 slugs fires ONCE on TenantCreated, not on requests.
- `notifyUsers` in handover: `DB::afterCommit` deferral confirmed.

---

## WU-3 — Policies Config

**WARNING: Setting::getValue in updatePackageSettings cross-field validator**
`ConfigurationController::updatePackageSettings` `$validator->after` closure calls
`Setting::getValue('package.reminder_after_days')` and/or `'package.return_after_days'` when
only ONE of the two day fields is submitted. At most 1 extra SELECT per admin config-update.
Fix: pre-read `Setting::whereIn('slug', ['package.reminder_after_days','package.return_after_days'])->pluck('value','slug')` BEFORE the closure and pass via `use`.
Low frequency (admin-only) but violates batch-read convention.

**Confirmed CLEAN in WU-3**
- `getPackageSettings` uses `Setting::whereIn(...)->get()->keyBy('slug')` — ONE batched SELECT for all 7 slugs. Do NOT re-flag.

---

## WU-4 — Retention Cron (ProcessPackageRetentionCommand)

**WARNING: Setting::getValue × 2 per tenant (lines 68-69)**
`processTenant()` calls `Setting::getValue('package.reminder_after_days')` and
`Setting::getValue('package.return_after_days')` as two separate raw Eloquent queries.
For M tenants: 2M queries vs M. Violates the same convention fixed in WU-3 for the same two slugs.
Fix:
```php
$settings = Setting::whereIn('slug', ['package.reminder_after_days','package.return_after_days'])
    ->pluck('value', 'slug');
$reminderAfterDays = (int) ($settings['package.reminder_after_days'] ?? 3);
$returnAfterDays   = (int) ($settings['package.return_after_days'] ?? 10);
```

**WARNING: Unbounded ->get() on READY packages per tenant (line 72)**
`Package::where('package_status_id', PackageStatus::READY)->with(['carrier'])->get()` loads ALL
ready packages into PHP memory at once. For large/old clusters this could spike memory on the
daily cron. `cursor()` incompatible with `with()` — use `->chunk(200, fn($pkgs) => ...)`.

**SUGGESTION: unitResidentUserIds() not deduped by unit_id within the loop (line 119)**
Called per reminder-eligible package. If a unit has N packages hitting the reminder threshold
on the same day, N identical `unit_user` SELECTs fire. In practice N is very small per day.
Fix: `$unitResidentCache[$unitId] ??= $this->unitResidentUserIds($unitId)` inside the loop.

**SUGGESTION: app(PackageService::class) resolved inside foreach (line 89)**
New instance per iteration; $settingCache resets. `returnPackage` doesn't call getSetting() so
no active waste, but pattern is inconsistent. Fix: resolve once before the loop.

**Confirmed CLEAN in WU-4**
- `saveQuietly()` for `reminded_at` stamp: correct in cron context — `auth()->user()` is null, bypass GlobalObserver intentionally.
- Per-package `returnPackage()` calls intentionally NOT batched — batching loses GlobalObserver audit stamps.
- `return` takes precedence over `reminder` (continue on line 92): correct FSM ordering.
- `with(['carrier'])` fires ONE Eloquent batch SELECT (not N+1). Acceptable.
- `NotifiesResidents::recordInApp` uses bulk `insertOrIgnore` — single INSERT for all recipients, not N.
- `PackageServiceProvider` uses `$this->app->booted()` vs spec's `callAfterResolving` — both work, no cost difference.
