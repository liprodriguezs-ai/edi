---
name: pattern-amenityreservations-s3-s5-review
description: AmenityReservations module S3-S5 (Amenity/AmenitySlot/AmenityBlackout/AmenityReservation domain models, controllers, services) review findings — mostly clean, one soft-delete unique-index bug
type: pattern
---

## Scope reviewed (2026-07-06, uncommitted, branch feat/amenity-reservations, base main)

6 migrations (`2026_07_06_100002..100007`), models
`{ReservationMode,PaymentTiming,Amenity,AmenitySlot,AmenityBlackout,AmenityReservation}`,
factories, controllers `{Amenity,AmenitySlot,AmenityBlackout}Controller`, 6 FormRequests,
`AmenityService` + `AmenityAvailabilityService`, `EnsureAmenityReservationsEnabled` middleware
+ provider alias registration, routes/api.php, CatalogSeeder edit.

## Verdict: clean, precedent-matching. One real bug found (see
[[pattern-soft-delete-aware-unique-index]]).

## What's done well (worth remembering as reusable precedent)

- `ReservationMode`/`PaymentTiming` central catalogs are a byte-for-byte structural mirror of
  `Modules\Collections\Models\AmountMode` (CentralCatalogModel, fixed UUID consts, same
  `$fillable`/`casts()` shape, upsertCatalog seeding, no factory). Good template to point to
  for the NEXT central-catalog module.
- `AmenityAvailabilityService` correctly does all "today"/date math in `tenant('timezone')`
  (never bare `Carbon::today()`/`now()`) — explicitly documented in its own class docblock as
  a deliberate guard against UTC-vs-tenant-timezone off-by-one-day bugs. Good template for any
  future date-range availability service.
- The `amenity`/`amenity_slot`/`amenity_blackout` Orion CRUD middleware
  (`['auth:sanctum', 'initializeTenancy', 'amenityReservationsEnabled']`) deliberately
  OMITS `rejectRefreshToken`/`authActive`, while the custom-action group
  (`availability`/`uploadMedia`/`deleteMedia`) ADDS them — this is not an oversight, it's an
  exact mirror of Collections' own `$tenancyMiddleware` vs `$customActionMiddleware` split
  (`Modules/Collections/routes/api.php:36-39`). Confirmed correct, not a violation.
- Per-tenant module kill-switch (`EnsureAmenityReservationsEnabled` +
  `AmenityReservationsSettings::ENABLED` Setting) self-registers its middleware alias in the
  module's own ServiceProvider (`registerMiddlewareAliases()`) rather than touching
  `bootstrap/app.php` — mirrors `User\UserServiceProvider`'s `termsAccepted` pattern. Good,
  reusable pattern for the next module needing a pre-GA dormant flag.
- `AmenityService::attachGalleryImage/detachGalleryImage` (extension/size double-validation:
  once in the controller via `mimes:`/`max:`, once again in the service via
  `ALLOWED_EXTENSIONS`/`MAX_BYTES`) is NOT redundant sloppiness — it's an exact mirror of the
  established `CommunityProjectService::attachDocument` / `AnnouncementService::attachFile`
  shape. Don't flag this duplication in future reviews of media-attach services; it's the
  house style.
- Reusing `Modules\Collections\Support\MoneyLimit::MAX_MINOR_UNITS` for cost/deposit input
  validation cross-module is acceptable (cross-module references between functional Kivo7
  modules — Amenity→Treasury for `bank_account`, Amenity→Housing for `unit` — are already the
  established norm per `.project-context/modules.md`'s dependency graph). Only worth a
  low-priority suggestion to eventually hoist to a core Support class, not a violation.

## The one real bug (WARNING severity)

`amenity` table's `unique(['tenant_id', 'name'])` (migration
`2026_07_06_100004_create_amenity_table.php:46`) is a PLAIN composite unique constraint, but
`AmenityCreateRequest`/`AmenityUpdateRequest` both validate name-uniqueness with
`->whereNull('deleted_at')` — i.e. the app layer explicitly intends to allow reusing a name
after soft-deleting the old amenity. The DB constraint doesn't honor that, so the FIRST time
an admin soft-deletes an amenity and recreates one with the same name, validation passes and
the INSERT throws a raw unique-violation (500) instead of a clean 422. Fix: swap to a partial
`DB::statement('CREATE UNIQUE INDEX amenity_tenant_name_unique ON "amenity" (tenant_id, name)
WHERE deleted_at IS NULL')`, mirroring `provider_tenant_name_unique` /
`charge_concept_tenant_code_unique`. See [[pattern-soft-delete-aware-unique-index]] for the
general checkpoint this created.

## Minor suggestion (not blocking)

`AmenityAvailabilityService::forRange()` issues one query per day inside its loop
(`hasHoldingByDayReservation`/`occupancyBySlot`), up to `MAX_RANGE_DAYS=31` queries for a
single request. Bounded and not a hot batch path, but could be one query for the whole range
grouped in memory — worth a look if `@infra-cost-reviewer` is run on this module later.
