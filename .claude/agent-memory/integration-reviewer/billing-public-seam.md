---
name: billing-public-seam
description: Phase-1 public billing seam (catalog + quoter) — Modules/SubscriptionPlan custom routes ↔ landing/PricingQuoter.astro. Contract, CORS gap, money display. Reviewed 2026-06-30.
metadata:
  type: project
---

# Public billing seam — catalog + quoter (Phase 1, reviewed 2026-06-30)

Backend lives in `Modules/SubscriptionPlan` (NOT Orion — custom `response()->json` routes).
Landing consumer: `landing/src/components/PricingQuoter.astro` (Astro island, vanilla fetch, NOT React).

## Endpoint map (both PUBLIC — no auth:sanctum, no initializeTenancy)
| Landing call | Backend route | Controller |
|---|---|---|
| GET `${PUBLIC_API_BASE_URL}/billing/catalog` | GET billing/catalog | BillingCatalogController@index → PricingService::catalog() |
| POST `${PUBLIC_API_BASE_URL}/billing/quote` | POST billing/quote | BillingQuoteController@quote → PricingService::quote() |

Both under `throttle:60,1` (shared per-IP bucket). Catalog cached `Cache::remember('billing.catalog', 300)`.

## Contract (VERIFIED ALIGNED 2026-06-30)
- catalog: `{currency, packages:[{key,name,type,pricing_type,included_modules:[],prices:{month?:{unit_amount,tier_mode,tiers},year?:{...}}}]}` — landing `Catalog`/`CatalogPackage` interfaces match field-for-field.
- quote req: `{units:int>=1, packages:string[], interval:'month'|'year'}` — matches BillingQuoteRequest rules.
- quote res: `{interval,currency,units,lines:[{key,name,qty,unit_amount,subtotal}],total}` — matches landing `QuoteResponse`/`QuoteLine`.
- Money = integer minor units (cents) on the wire; landing divides /100. Backend is sole authority — landing never computes amounts and in fact never reads catalog `prices` (only key/name/type/included_modules for checkboxes).
- `included_modules` = module `name` strings. Seeder syncs by exact name; all 4 verified present in modules.csv: `Cobros`(L37), `Avisos`(L63), `Control de Accesos`(L71), `Paquetería`(L90). If a name drifts from modules.csv the array silently comes back empty.

## Conventions confirmed for central billing models
- `BillingPackage`/`BillingPackagePrice` use `CentralConnection` + NO `BelongsToTenant` — matches sibling central models (SubscriptionPlan, Country, System, User). Correct for a tenant-agnostic catalog.
- `unit_amount` cast `integer` (not MoneyIntegerCast) — deliberate, mirrors SubscriptionPlan.price, keeps raw cents on the JSON wire.
- `type`/`pricing_type`/`interval`/`tier_mode` = string columns + class constants (no PHP enum) per laravel-backend-architecture rule 14.
- BillingPackageSeeder is CENTRAL (`DatabaseSeeder`, after ModuleTableSeeder so modules exist), NOT TenantCatalogSeeder. Prices are random placeholders.

## Findings (verdict ISSUES — contract aligned, prod-config + hardening gaps)
- HIGH: CORS — landing prod origin is NOT in backend `CORS_ALLOWED_ORIGINS` (default = localhost:5173, 127.0.0.1:5173, plankton-app-pngw3.ondigitalocean.app = the React app, not the marketing site). Works in local (`allowed_origins=['*']`), browser BLOCKS in prod. Must add landing origin(s) to the env. `config/cors.php` covers `api/*` so routes are in scope.
- LOW: `units` has no max (FormRequest + landing input) → absurd/abuse quotes. Add `max`. [CORRECTED 2026-06-30: quote FormRequest now has `units` max:100000 — matches checkout. Resolved.]
- LOW: landing `fmt` uses `maximumFractionDigits:0` → drops cents; deviates from project MXN display convention (2 decimals). Fine only if real prices stay whole-peso.
- LOW: 429 (shared 60/min bucket for catalog+quote) only shows generic error on landing.
