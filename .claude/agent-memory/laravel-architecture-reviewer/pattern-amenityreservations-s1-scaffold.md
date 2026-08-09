---
name: pattern-amenityreservations-s1-scaffold
description: AmenityReservations module S1 slice — clean central-catalog scaffold, but recurring "dead module:make stub" finding + staged registration deferral to confirm at S2/S17
metadata:
  type: pattern
---

Reviewed the S1 (scaffold) slice of the new `AmenityReservations` module (branch `feat/amenity-reservations`, uncommitted). Only a `reservation_status` central catalog + a per-tenant kill-switch setting land in this slice; domain resources (amenity, amenity_reservation) come later.

**What's correct (high-fidelity mirror of established patterns):**
- `ReservationStatus` model matches `ChargeStatus`/`PackageStatus` EXACTLY: `CentralCatalogModel` + `#[ObservedBy([GlobalObserver::class])]` + fixed UUID class constants + `$fillable` incl. `id` + `casts()` for `is_active`/`order`.
- Migration: table comment first inside closure, `BaseModel::columns($table, false)` for CENTRAL (no tenant_id), every own column commented. Correct.
- `ReservationStatusController` matches `ChargeStatusController` byte-for-byte in shape (sortableBy/filterableBy only, no includes needed for a catalog).
- `CatalogSeeder::seedReservationStatuses()` uses the shared `upsertCatalog()` helper — idempotent by construction.
- `AmenityReservationsSettings` + `AmenityReservationsDatabaseSeeder` mirror `Collections\Support\CollectionSettings` + the CURRENT (withTrashed/restore) version of `CollectionsDatabaseSeeder`, not the older firstOrCreate version — correctly picked the up-to-date idempotent pattern (soft-deleted row still occupies the non-partial `unique(tenant_id, slug)` index).
- Providers (`*ServiceProvider`, `EventServiceProvider`, `RouteServiceProvider`) are byte-identical nwidart stubs to `Announcements` — no drift.
- Tests use the shared `Tests\Support\WithTenantSetup` trait correctly (no copy-pasted seedTenant/makeTenant).
- `modules_statuses.json` has the new module registered `true`. Composer merge-plugin glob (`Modules/*/composer.json`) auto-picks up the new module — no root composer.json edit needed.

**Recurring finding — same class as a past Package-module note:** `routes/web.php` left a LIVE (uncommented) `use Modules\AmenityReservations\Http\Controllers\AmenityReservationsController;` import for a controller that was never created (only `ReservationStatusController` exists) — leftover from the `php artisan module:make` scaffold, not cleaned up before commit. Harmless at runtime (PHP resolves `use` lazily, and the only reference to the class is inside a commented-out route block), but it's exactly the "no bogus auto-generated stubs left behind" anti-pattern the laravel-backend-architecture skill calls out. Worth a quick automated grep across new modules going forward: `grep -rn "^use.*Controllers\\\\.*Controller;" Modules/*/routes/web.php` then verify the class file actually exists.

**Deferred-by-design, not a violation (confirm it actually lands later):** no `modules.csv`/`permissions.csv`/`module_system.csv`/`SystemTableSeeder`/`RolePermissionTableSeeder` entries yet, and no `moduleEnabled:*`-style middleware gate wired to the new `amenity_reservations.enabled` per-tenant kill-switch on the `reservation_status` Orion route. The module's own code comments explicitly say permissions + menu/entitlement wiring land in S2/S17 — this reads as an intentional staged rollout (mirrors how Collections' `moduleEnabled:charge_batch` gate was added only once the module went live), not an oversight. Flag it again at the S2/S17 review if the gate still isn't wired by then.
