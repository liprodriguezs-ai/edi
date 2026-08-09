---
name: tracking-module-central
description: Tracking module (marketing conversion events) is correctly CENTRAL — TrackingEvent has no tenant_id, uses CentralConnection; /api/track is public with no auth:sanctum/initializeTenancy; verified SAFE.
metadata:
  type: pattern
---

# Tracking module — CENTRAL classification, verified SAFE

`backend/Modules/Tracking/` dispatches marketing conversion events (Lead, StartTrial,
CompleteRegistration, InitiateCheckout, Purchase) to Facebook Conversions API + GA4
Measurement Protocol. Reviewed 2026-07-01, no CRITICAL/WARNING isolation findings.

**Classification: CENTRAL, correctly implemented.**
- `Modules/Tracking/app/Models/TrackingEvent.php` — `use CentralConnection;` (Stancl trait,
  forces `getConnectionName()` = `config('tenancy.database.central_connection')`), NO
  `BelongsToTenant`. Same pattern as `SubscriptionPlan`, `Country`, `System`,
  `UserInvitation`, `TenantEntitlement`, `BillingPackage(Price)`, `Municipality`,
  `TenantPaymentType` — an established, consistent convention across the codebase.
- Migration `database/migrations/2026_07_01_000001_create_tracking_event_table.php` —
  `BaseModel::columns($table, false)` (no `tenant_id`), unique on `['event_id','channel']`
  (correctly NOT tenant-scoped since there's no tenant_id to scope by — this is intentional
  audit-log dedup, not a business-unique that needs tenant scoping).
- `module.json` has no `"tenancy"` key — correct, that key was removed project-wide (see
  `.project-context/modules.md`); classification is expressed only via
  `BaseModel::columns()` + presence/absence of `BelongsToTenant`.

**Route/middleware chain confirmed public, as designed:**
- `routes/api.php`: `Route::middleware('throttle:60,1')->post('track', ...)` — only `api` +
  `throttle` middleware from the module's `RouteServiceProvider::mapApiRoutes()`. NO
  `auth:sanctum`, NO `initializeTenancy` anywhere in the chain.
- Global `EnsureSubscriptionActive` (appended to the `api` middleware group in
  `bootstrap/app.php`) DOES run on `/api/track` too (not allowlisted), but it passes
  through unauthenticated requests (`$user === null` early-return) and its tenant
  resolution (`ActiveTenantResolver`) is a pure read with no `tenancy()->initialize()` side
  effect — confirmed no tenant-scope leak. Only non-isolation observation: an
  AUTHENTICATED user whose tenant is blocked would get 403 on `POST /api/track` (not in the
  ALLOWLIST) — a product/UX question (should tracking beacons still fire for blocked
  clusters?), not a tenancy breach.
- Verified by a real passing test: `tests/Feature/Tracking/TrackEndpointTest.php::test_endpoint_requires_no_authentication`.

**Server-side hooks (`StripeWebhookListener::dispatchPurchaseTracking`,
`AuthController::dispatchRegistrationTracking`) do not depend on tenant scope:**
- Both call `TrackingDispatcher::dispatch()` which queues one `DeliverTrackingEventJob` per
  channel; the job's `handle()` only writes to the central, unscoped `TrackingEvent` model —
  so even though `QueueTenancyBootstrapper` may carry the ambient `tenant_id` into the job
  payload and re-initialize tenancy on the worker (see [[stancl-queue-bootstrapper]]), it is
  irrelevant here: nothing in the job's write path is gated by `TenantScope`.
- Facebook/GA4 credentials are read from `config('services.facebook.*')` /
  `config('services.google_analytics.*')` (env-backed, global product-level), never from a
  tenant-scoped `Setting` — matches the stated design intent (credentials are global, not
  per-cluster).

**Verdict:** SAFE. No cross-tenant leak risk; classification, migration, route, controller,
request, dispatcher, channels, and hooks are all consistent with CENTRAL treatment.
