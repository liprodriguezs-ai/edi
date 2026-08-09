---
name: amenity-reservations-s9-s11
description: AmenityReservations module (surcharges/notifications/expiry cron) review findings and a general Eloquent global-scope+orWhere safety discovery
metadata:
  type: discovery
---

Reviewed slices S9 (reservation_surcharge), S10 (notification wiring: rejection
observer + ReservationService notify methods + NotificationEvent/Category/seeders),
S11 (CancelExpiredReservationsCommand) of `Modules/AmenityReservations`. Overall
very high compliance with laravel-backend-architecture skill (migration comments, BaseModel::columns,
TenantMoneyCast, DB::afterCommit via NotifiesResidents, row-lock-and-recheck FSM
pattern, `Tenant::all()+run()` cron mirroring `RefreshChargeStatusesCommand`).

**Findings (both minor):**
1. `AmenitySurchargeService::create()` (`Modules/AmenityReservations/app/Services/AmenitySurchargeService.php`)
   omits `grace_days` with a comment claiming this is "same as AmenityChargeService's
   charge" — it is NOT: `AmenityChargeService::createChargeForReservation()` hardcodes
   `grace_days => 0` (moot there since that charge is born-paid atomically in the same
   transaction). The surcharge charge is a REAL open debt that can sit unpaid, so falling
   back to the tenant's `collections.grace_days` default (via `charge.grace_days` nullable
   + `ChargeStatusService::resolveThresholds()`) is actually the MORE correct behavior —
   just the comment is factually wrong about why. Doc-only fix, not a functional bug.
2. `reservation_surcharge.created_by` is NOT NULL in the migration, but the service
   writes `$this->currentUser()?->id` (nullsafe). In practice unreachable (route requires
   `auth:sanctum` + `update.amenity_reservation`), but if ever called outside an
   authenticated HTTP context (tinker/queued job) it would throw a raw NOT NULL
   constraint violation instead of a clean guard. Low severity.
3. **Established (not new) module convention worth tracking**: this module uses
   dedicated `FormRequest` classes for custom (non-CRUD) POST actions —
   `AmenityReservationRejectRequest`, `AmenityReservationCancelRequest`,
   `AmenityReservationSurchargeRequest` for `/reject`, `/cancel`, `/surcharges`. This
   contradicts laravel-backend-architecture skill §2.10/§2.12, which says custom actions validate
   inline via `$request->validate([...])`, no FormRequest. Precedent started in S7
   (reject/cancel, already reviewed) and S9 continued it consistently. Flag again if
   asked to review S7 in isolation; not re-litigating it every slice since it's now an
   established, internally-consistent module pattern — but it IS a real skill deviation,
   not a false positive.

**General discovery (applies to ALL future reviews, not just this module):**
Eloquent's `Builder::callScope()` (used by `applyScopes()` when a `Scope` object like
Stancl's `TenantScope` is applied) automatically NESTS pre-existing `where()`/`orWhere()`
clauses added by user code into their own group when the scope adds more wheres
afterward — i.e. `Model::query()->where(fn)->orWhere(fn)->get()` on a `BelongsToTenant`
model compiles to `WHERE ((cond1) OR (cond2)) AND tenant_id = ?`, NOT the naive/broken
`WHERE (cond1) OR ((cond2) AND tenant_id = ?)`. Verified by reading
`vendor/stancl/tenancy/src/Database/TenantScope.php` (plain top-level `$builder->where(...)`,
no manual nesting) plus Laravel's own `shouldNestWheresForScope`/`nestWheresForScope`
protection. So: top-level `orWhere` combined with a global tenant scope is SAFE by
default in this codebase — do not flag it as a cross-tenant leak without first checking
whether `withoutGlobalScope`/`withoutTenancy` was used to bypass the scope entirely.
Example verified safe: `CancelExpiredReservationsCommand::expireOverdueReservations()`.
