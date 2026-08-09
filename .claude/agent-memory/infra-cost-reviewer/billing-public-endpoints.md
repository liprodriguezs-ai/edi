---
name: billing-public-endpoints
description: Cost review of Phase-1 Kivo7 billing (public catalog + quoter) in Modules/SubscriptionPlan; what's clean and the one real waste (quote ignores the cached catalog)
metadata:
  type: project
---

Phase-1 billing = public catalog + quoter, lives in `Modules/SubscriptionPlan`
(BillingCatalogController, BillingQuoteController, PricingService, BillingPackage
+ BillingPackagePrice models, billing_package(_price|_module) migrations,
BillingPackageSeeder). Landing quoter: `landing/src/components/PricingQuoter.astro`.
Reviewed 2026-06-30.

**Why:** explicit project goal = minimize infra spend; these are the only
UNAUTHENTICATED public endpoints, so they're hit by every landing visitor.

**Confirmed CLEAN (do not re-flag):**
- `PricingService::catalog()` wraps the query in `Cache::remember('billing.catalog', 300, ...)`
  and eager-loads `prices` + `modules:id,name` → no N+1 building the catalog.
- Both routes throttled `throttle:60,1` (per-IP) in `SubscriptionPlan/routes/api.php`.
- Landing quoter is cost-conscious: 350ms debounce on the units input, `quoteToken`
  stale-response guard, ONE catalog+quote pair on mount (paintInterval('month') does
  NOT fire a quote). Interval/checkbox toggles fire an immediate quote but are
  user-click frequency.
- Module-name mapping in the seeder is correct: base→`Cobros`,`Avisos`,`Control de Accesos`;
  addon→`Paquetería` (all exist in `database/seeders/csv/modules.csv`; Paquetería is
  group `Portería`, line ~90). ModuleTableSeeder runs BEFORE BillingPackageSeeder in
  DatabaseSeeder, so the pivot sync resolves. Do not re-flag as "missing module".
- `CentralConnection` on the two models matches the sibling `SubscriptionPlan` model;
  a `central` connection IS configured in config/database.php. Consistent.

**The one real waste (WARNING) — `PricingService::quote()` ignores the cached catalog:**
quote() issues TWO DB queries per call — `keyTypes()` (`whereIn('key')->pluck('type')`)
plus the main `whereIn('key')->with('prices')->get()` — even though `catalog()` already
caches every active package + price + tier + type for 300s. quote() is the HOTTEST
public path (fires on landing mount, every interval toggle, every addon toggle, every
debounced units change). Fix options: (A best) derive package/price data from the cached
catalog array → 0 DB queries on cache hit; (B minimum) drop the redundant `keyTypes()`
query and check `$packages->contains(fn($p)=>$p->type===TYPE_BASE)` from the already-loaded
collection → 2 queries become 1. Bounded by throttle + tiny table, so WARNING not CRITICAL,
but the cache to avoid it sits 10 lines up.

**Minor (SUGGESTION):** catalog cache never invalidated (seeder/admin edits stale for ≤300s
— add `Cache::forget('billing.catalog')` to the seeder + future write paths); `packages`
array in BillingQuoteRequest has no `max` (unbounded whereIn on a public endpoint — add
`max:N`); graduated tier line reports base `unit_amount` while `subtotal` is the graduated
sum (unit_amount*qty != subtotal — contract clarity, landing doesn't render unit_amount).

**Convention reaffirmed:** public unauthenticated endpoints here MUST be cached + throttled;
any per-request work (like quote) that can read from an existing short-TTL cache SHOULD,
instead of re-querying. PricingService is the server-side pricing authority by design
(client never computes amounts) — respect that; the cost lever is caching the authoritative
result, not moving the math to the client.
