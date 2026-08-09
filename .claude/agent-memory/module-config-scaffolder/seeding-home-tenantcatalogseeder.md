---
name: seeding-home-tenantcatalogseeder
description: module-config defaults are seeded in TenantCatalogSeeder::seedXSettings(), NOT the module's own XDatabaseSeeder (agent-instruction says XDatabaseSeeder but live pattern differs)
metadata:
  type: project
---

Per-tenant module-config Setting defaults live in `backend/database/seeders/TenantCatalogSeeder.php`
as a private `seedXSettings()` method called from `run()` — NOT in the module's own
`XDatabaseSeeder` (which is not wired into the tenant seed chain at all).

**Why:** The live chain is `TenantCreated` → `tenants:seed` → `TenantDatabaseSeeder`
(`Modules/Tenant/database/seeders/`) → `TenantCatalogSeeder`. Package (`seedPackageSettings`)
and Complaint (`seedComplaintSettings`, added 2026-07-19) both follow this. The design docs
(e.g. complaints design §11) also name TenantCatalogSeeder as the home.

**How to apply:** When scaffolding a new module's config, add `seedXSettings()` to
TenantCatalogSeeder with `Setting::firstOrCreate` per slug, and ignore the base-instruction
mention of the module's XDatabaseSeeder unless the orchestrator says otherwise. Also: unlike
Package's getPackageSettings (returns null for absent rows), prefer the Delinquency/Complaint
mold where GET falls back to the manifest defaults — keeps existing tenants (created before
the module) working without a reseed.
