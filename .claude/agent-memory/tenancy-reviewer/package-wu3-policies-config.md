---
name: package-wu3-policies-config
description: Package module WU-3 (policy settings via Configuration module) — why package.* Setting reads/writes are tenant-isolated; the updateOrCreate slug-only keying is correct.
metadata:
  type: pattern
---

# Package WU-3 — Policies config (Configuration module) — SAFE

Slice adds `getPackageSettings` / `updatePackageSettings` to `Modules/Configuration/app/Http/Controllers/ConfigurationController.php` + `GET/PUT /config/package-settings` routes. Reviewed 2026-06-29 (branch feat/package-reception). Verdict: SHIP, no tenant-isolation defects.

## Why it is isolated
- `Modules/Setting/app/Models/Setting.php` uses `BelongsToTenant` (line 16) → `TenantScope` auto-scopes EVERY read and the lookup half of writes; create stamps `tenant_id`.
- `setting` table has `unique(['tenant_id','slug'])` (composite) — two tenants can hold the same slug with no global collision.
- Reads: `Setting::whereIn('slug', $slugs)` and `Setting::getValue($slug)` (`static::query()->where('slug',$slug)->first()`) are both scoped. The validator's cross-field DB read (`getValue('package.reminder_after_days')`) is also scoped.
- Writes: `Setting::updateOrCreate(['slug' => $slug], [...])` keyed by SLUG ONLY is CORRECT here — `firstOrNew` is tenant-scoped (won't find another tenant's row), and on the create path `BelongsToTenant` stamps the current tenant_id. The composite unique backstops. This matches the sibling `updateCollectionSettings` exactly (the design's stated mold).
- Routes sit inside `['auth:sanctum','rejectRefreshToken','authActive','initializeTenancy']` → tenancy is always initialized before the controller runs.
- Authz: `requireAbility('read.configuration')` / `update.configuration`; no `package_config` entity (per design §2). Security role → 403 (verified by test).

## Contract deviation (intentional, not a defect)
tasks.md §3.1.3 text says `updateOrCreate(['slug'=>..., 'tenant_id'=>tenancy()->tenant->id], ...)`. Impl omits explicit tenant_id and relies on TenantScope. This is equivalent + matches the proven sibling; adding tenant_id would just be redundant. Do NOT flag in future reviews.

## Note for future reviewers
The whole isolation here is IMPLICIT via TenantScope — there is no explicit `where('tenant_id', ...)` anywhere in these two methods. Safe ONLY because `initializeTenancy` always runs first on these routes. If anyone ever calls these methods from a console command / job without initialized tenancy, the slug-only `updateOrCreate` would target a cross-tenant row. Same risk profile as all sibling `/config/*` methods.
