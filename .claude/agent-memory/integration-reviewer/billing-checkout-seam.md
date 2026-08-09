---
name: billing-checkout-seam
description: Phase-2 Slice-2 authenticated checkout seam — POST /api/billing/checkout {units,packages,interval}->{url}, error shapes (422/403/409), webhook<->entitlement<->menu projection. Backend-only diff; frontend Slice 3 pending. Reviewed 2026-06-30.
metadata:
  type: project
---

# Authenticated checkout + entitlement-on-payment seam (Phase 2 / Slice 2, reviewed 2026-06-30)

Backend-only diff. Frontend Slice 3 NOT built yet — this review judges the contract Slice 3 WILL consume.
Files: `BillingCheckoutController`, `BillingCheckoutRequest`, `SubscriptionPlan/routes/api.php`,
`app/Listeners/StripeWebhookListener.php`, `BackfillEntitlementsCommand`.

## Endpoint contract (NEW)
- `POST /api/billing/checkout` — middleware `auth:sanctum, rejectRefreshToken, authActive, initializeTenancy`.
- Request: `{ units:int 1..100000, packages:string[] 1..20 (catalog keys, MUST include literal 'base'), interval:'month'|'year' }`.
- Success: `200 { url:string }` (Stripe Checkout URL). Field names flat snake-free; no camel/snake drift.
- Owner-only: subscription/card belongs to the tenant's single `account.owner`. Mirrors legacy
  `/config/subscription/checkout` (ConfigurationController) exactly: same 409 guard
  (`subscription('default')->stripe_status in [active,trialing]`), same `success_url/cancel_url`
  = `config('app.frontend_url').'/select-plan?success=true|canceled=true'`.
- Both checkouts share the SAME Cashier subscription name `default` → mutually exclusive (one default sub per owner). Coherent, no double-sub.

## ALIGNED (reinforce)
- Redirect URLs land on `/select-plan` and `SelectPlanPage.tsx` ALREADY reads `?success=true` / `?canceled=true` (useSearchParams). No drift with legacy.
- `interval` is `'month'|'year'` across catalog + quote + checkout. `units`/`packages` rules now identical between quote and checkout (both max:100000 / max:20). Slice 3 can reuse one type.
- Menu/projection NO drift: `EntitlementService::recompute` writes exactly what `MenuController` reads — `system_tenant` (is_addon=false) via SystemTenantService + `module_tenant.enabled` via ModuleTenantService::disabledModuleIds. Granted modules derived from active entitlements' billing packages → parent systems via module_system.
- React-app origin already in CORS allowed origins (authenticated endpoint, called from app, NOT the landing site) — the Phase-1 landing CORS gap does NOT apply here.

## FINDINGS (verdict ISSUES — contract sound, Slice-3 ergonomics + 1 edge-correctness gap)
- MEDIUM (error shape, the main one): 422 is overloaded with NO machine-readable `code`. Four distinct cases:
  (a) FormRequest validation → `{message, errors:{field:[]}}`; (b) contact_sales → `{message}`;
  (c) owner unresolved (0 or >1 owners) → `{message}`; (d) no billable Stripe price → `{message}`.
  Slice 3 cannot branch (contact-sales CTA vs misconfig vs form error) without string-matching Spanish
  or a redundant `/billing/quote` pre-check. INCONSISTENT with /billing/quote which exposes `contact_sales`
  as a structured bool. FIX: add stable `code` to the non-validation 422s.
- MEDIUM (403 UX coupling): apiClient response interceptor `handle403()` fires the GLOBAL PermissionsModal
  for EVERY 403. The checkout 403 ("solo el propietario puede iniciar el pago") will pop the generic
  "permisos" modal (it does render the backend `message`). Legacy `useSelectPlan` already works around this
  by NO-OPing locally on 403. Slice 3 MUST mirror that, or the seam shows a permissions-framed modal for a
  billing rule. NB: owner-unresolved yields 422 (not 403) for the same human action when owner cardinality≠1.
- LOW (409 has no global handler): Slice 3 must explicitly catch 409 → route to portal/change-plan. Not auto-handled.
- LOW (magic-string coupling): `BillingCheckoutRequest::mustIncludeBase()` hard-codes literal `'base'`.
  Slice 3 must send the catalog package whose `type==='base'` AND that package's `key` must equal `'base'`
  (seeder uses key 'base' today). Prefer validating base by type / exposing base key in catalog.
- LOW (payment-path edge correctness): in `handleCheckoutCompleted`, if `Cashier::stripe()->subscriptions->retrieve`
  fails (non-expanded subscription + network error) → `subscriptionItems()` returns [] → NO entitlements granted →
  recompute never runs, but tenant is ALREADY set STATUS_ACTIVO. A fresh tenant with empty system_tenant then hits
  MenuController's fallback = "show ALL systems except Core" (over-grants menu). Self-heals on next
  subscription.updated / invoice.payment_succeeded reconcile. Consider deferring ACTIVO until items resolve.

## Webhook<->entitlement model (verified coherent)
- Idempotent by Stripe event id (`Cache::add stripe:evt:{id}`, 4-day TTL > retry window). Handler never throws (always 200).
- Activation gated on `payment_status in [paid, no_payment_required]` (OXXO/transfer 'unpaid' does NOT activate — correct for MX async methods).
- TenantEntitlement.active() = `expires_at IS NULL OR > now()`. legacy_plan/super_admin_free granted with NULL expires_at (perpetual); stripe sets expires_at=period_end. deleted → expires_at=now() → inactive → recompute turns modules off. past_due/unpaid → PENDIENTE_PAGO but entitlements left active (grace).
- Tenant resolved BY ID from metadata.tenant_id (preferred) or customer→owner→single-tenant fallback. NEVER calls tenancy()->initialize() (webhook is unscoped). Tenant is central; EntitlementService acts on explicit $tenant.
- BackfillEntitlementsCommand: every legacy subscription_plan_id tenant → ONE `base` entitlement (source legacy_plan), idempotent, --dry-run. Must run BEFORE entitlement checkouts so recompute has a base to project.
