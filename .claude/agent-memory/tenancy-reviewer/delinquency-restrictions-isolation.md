---
name: delinquency-restrictions-isolation
description: delinquency-restrictions review — scoped() memo verified safe on HTTP+queue, but scoped() gives ZERO protection inside tenant-loop console commands; my-restrictions omits rejectRefreshToken/authActive
metadata:
  type: project
---

Reviewed the `delinquency-restrictions` change (2026-07-14). Isolation verdict: SAFE, no cross-tenant leak. Two things worth carrying forward.

## The `scoped()` + per-request memoization pattern — VERIFIED, with one hole

`DelinquencyRestrictionService` memoizes six `delinquency.*` Setting rows in a
`private ?array $settingsCache` and is bound `$this->app->scoped(...)` in
`CollectionsServiceProvider`. Verified against vendor:

- **Octane**: safe. `config/octane.php` registers `FlushTemporaryContainerInstances`
  under `OperationTerminated` (an *interface*, implemented by `RequestTerminated`, so
  Laravel's `addInterfaceListeners` does fire it), which calls `forgetScopedInstances()`.
  Belt-and-braces: Octane clones the app into a per-request sandbox, and `$instances` is
  an array property copied by value on clone — an instance resolved during a request never
  reaches the base app anyway.
- **Queue**: safe. `QueueServiceProvider` builds a `$resetScope` closure calling
  `$app->forgetScopedInstances()`; `Worker` invokes it per job iteration (Worker.php:224).
- **No other consumer pins it**: no `singleton()` binding anywhere in `Modules/`+`app/`
  captures it (only `PaymentEarmarkRegistry`, class-strings only). Its injected
  `ChargeStatusService` has no static/memo state.

**The hole — `scoped()` does NOTHING in console commands.** `forgetScopedInstances()` is
never called inside a `Tenant::all()` + `$tenant->run()` loop, so one scoped instance (and
its memo) spans every tenant in the run. Two commands ALREADY hold it across tenants:

- `Modules/AmenityReservations/app/Console/Commands/CancelExpiredReservationsCommand.php:53`
  — `handle(ReservationService $reservationService)` method-injects ONCE before the loop
  at :55/:73.
- `Modules/Package/app/Console/Commands/ProcessPackageRetentionCommand.php:38` — resolves a
  fresh `PackageService` *inside* `$tenant->run()` (comment says so), but the nested
  `DelinquencyRestrictionService` is scoped → same instance every tenant. The "fresh per
  tenant" comment is true of PackageService's own `$settingCache`, NOT of the delinquency memo.

Latent only today: the guard is reached solely from `ReservationService::create()` and
`PackageService::register()/batchRegister()`, none reachable from `expire()` /
`notifyPaymentReminder()` / `processRetention()`. Becomes a live cross-tenant bleed the
moment anyone adds a delinquency check to a cron-reachable method — `notice_text` is
admin-editable free text, so tenant A's notice would render into tenant B's notification.

**Rule for future reviews:** a per-instance memo of tenant-scoped data is only as safe as
its *binding lifecycle*. Prefer keying the memo by `tenant()->getTenantKey()` — correct
under scoped, singleton, AND command-held instances. Do not accept `scoped()` alone as
proof of isolation when the class is reachable from a tenant loop. See
[[package-wu4-retention-cron]] (`Setting::getValue` with no static memo — the safe shape).

## `my-restrictions` middleware drift

`Modules/Collections/routes/api.php:113` uses a hand-rolled
`['auth:sanctum','initializeTenancy']`, omitting `rejectRefreshToken` (sec-authn-04) and
`authActive` (sec-authn-10, whose middleware docblock claims "EVERY authenticated request").
Collections' own `$customActionMiddleware` (:40) and Package's group (:17) both include them.
Not a tenant leak (payload is caller's own units, zero user input) but reopens two closed
findings for one route. Tenancy itself is fine.

## Confirmed-correct patterns (do not re-flag)

- `restrictedUnitIds()` / `stateForUnits()` / `evaluate()`: bare `Unit::query()` /
  `Charge::query()` — both models `use BelongsToTenant`; no `withoutGlobalScope` /
  `withoutTenancy` / raw SQL anywhere in the change.
- `CollectionsDatabaseSeeder` fail-CLOSED: `if (! tenancy()->initialized) return;` before
  any write — cannot write null-tenant rows. `Setting::withTrashed()` drops only
  SoftDeletingScope, never TenantScope.
- `BackfillDelinquencySettingsCommand` uses canonical `Tenant::all()` + `$tenant->run()`.
  Note Stancl's `TenantRun::run()` has **no try/finally** — a throw leaves tenancy
  initialized as the failed tenant; harmless here only because the exception aborts the
  whole command.
- `ChargeStatus` is a `CentralCatalogModel` with fixed UUID constants → `ChargeStatus::OVERDUE`
  comparisons are tenant-agnostic and correct.
- `DelinquencyRestrictedException::forGuard()` structurally cannot carry an amount (takes no
  money arg) — audience split enforced by the type, not by call-site discipline.
