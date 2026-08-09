---
name: project-overview
description: Kivo7 backend architecture — stack, patterns, conventions for architectural review
metadata:
  type: project
---

Laravel 13 / PHP 8.4 / Orion REST / Nwidart modules / Stancl single-DB tenancy.

**Key patterns:**
- Controllers extend `QCH\Core\Http\Controllers\Controller` (Orion base), define `$model` + `$keyForPermission`
- Business logic in Services; Controllers delegate via `performStore`/`performUpdate` overrides
- All money = bigInteger minor units + `Cknow\Money\Casts\MoneyIntegerCast`
- Statuses = catalog tables with UUID constants, NEVER PHP enums
- Tenant isolation = `BelongsToTenant` trait + `TenantScope`; single DB
- Migrations: every own column needs `->comment()`; table comment inside closure
- Custom actions = standalone `Route::post` + method returning `JsonResponse`

**Audit baseline SHA:** 803df3a
**Last audit:** 2026-06-12

**Why:** Reference for all future reviews of this project.
**How to apply:** Apply project conventions on top of laravel-backend-architecture/stancl-tenancy skills.
