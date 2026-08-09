---
name: billing-provision-enforcement-seam
description: Billing fase1 seam — POST /tenant/provision (super-admin alta+billing), 403 SUBSCRIPTION_INACTIVE enforcement middleware, SubscriptionStateService status contract. Reviewed 2026-06-30, NO criticals.
metadata:
  type: project
---

# Billing fase1 provision + enforcement seam (reviewed 2026-06-30, branch feat/billing-fase1)

Static contract review. Verdict: ALIGNED, zero criticals. Four backend new files
(TenantProvisioningController/Request/Service, EnsureSubscriptionActive, ActiveTenantResolver,
SubscriptionStateService, config/billing.php) ↔ FE (TenantProvisionForm, WizardPlanSelector,
tenant/schema.ts, TenantResource.provision, apiClient 403, Session.ts, SelectPlanPage).

## POST /api/tenant/provision (route: Modules/Tenant/routes/api.php, mw auth:sanctum,rejectRefreshToken,authActive; gate create.tenant in controller ctor)
- Request shape EXACT-match FE↔BE. FE IProvisionTenantRequest {name, payment_method, provision_mode,
  units?, interval?, packages?, notes?}. Backend TenantProvisionRequest ALSO accepts timezone/currency/
  type/uses_ownership_share (all nullable, DB defaults) — FE never sends them = benign superset, NOT drift.
- Enums EXACT: provision_mode ∈ {trial,courtesy,manual_paid,card} (TenantProvisioningService::MODES ==
  z.enum). payment_method ∈ {card,transfer,cash} (Tenant::PAYMENT_METHODS). interval ∈ {month,year}
  (BillingPackagePrice::INTERVALS == BillingInterval).
- Conditional-required aligned: units+interval required iff manual_paid (FE sends them only for manual_paid
  via planSelection); packages required iff courtesy||manual_paid (FE `needsPlan`). base always present:
  useBillingQuoter selects ALL keys on load + togglePackage refuses to drop base → WizardPlanSelector
  selectedPackages always contains base → backend assertHasBase never trips. NOT a critical (was the risk).
- Cross-field (mode/method) validated BOTH sides: BE withValidator adds error on payment_method; FE
  superRefine mirrors + a useEffect auto-corrects method on mode change → backend combo-422 practically
  unreachable.
- Money: PricingService is sole authority; FE never sends amounts on provision. Quote total minor units,
  formatPrice ÷100. ✓
- Response {message, data:{tenant, subscription, entitlements_created}} 201. FE unwraps data.data.tenant only
  (TenantResource.provision). subscription + entitlements_created IGNORED by FE = fine. NB: response
  `subscription` = SubscriptionStateService::forTenant($actor=super-admin,$tenant) → is_owner=false and
  card-mode shows 'none'/blocked; purely informational, unused. TenantWizardPage uses tenant.id only.

## 403 { code:'SUBSCRIPTION_INACTIVE' } enforcement (EnsureSubscriptionActive appended to `api` group in bootstrap/app.php)
- EXACT string match: BE returns {message, code:'SUBSCRIPTION_INACTIVE'} 403; FE apiClient handle403 reads
  errorData?.code === 'SUBSCRIPTION_INACTIVE' → toast + redirect /select-plan (once, via subscriptionInactiveHandled).
  ApiErrorPayload has code?:string so it survives into ApiError.payload. ✓
- Ordering edge (benign): handle403 checks skipPermissionsModal BEFORE the SUBSCRIPTION_INACTIVE branch. But
  every skipPermissionsModal endpoint (billing/*, config/subscription*) is server-side ALLOWLISTED in the
  middleware AND super-admin/lawyer are exempt, so those never return SUBSCRIPTION_INACTIVE → no conflict.
- Kill-switch config('billing.enforce_subscription') (env BILLING_ENFORCE_SUBSCRIPTION, default true).
  phpunit.xml disables it. Allowlist keeps auth/*, billing/*, config/subscription*, config/plan, onboarding*,
  app-config, stripe/* reachable while blocked.
- ActiveTenantResolver is the shared resolver used by BOTH InitializeTenancyFromUser and the gate → closes
  the X-Tenant-Id enforcement-vs-serving divergence. Pure, no side effects (persist stays in the middleware).

## SubscriptionStateService status contract (drives /auth/me,login,register `subscription` + provision response)
- Backend statuses: {none, active, trial, expired, exempt} + legacy path passes through raw Stripe
  stripe_status (active/trialing/past_due/canceled/incomplete/incomplete_expired/unpaid). FE Session.ts
  SubscriptionStatus union = superset of ALL of these → every backend status is contemplated. ✓
- State shape {status, is_blocked, is_owner, trial_ends_at?, expires_at?} == FE SubscriptionState. trial adds
  trial_ends_at+expires_at; active adds expires_at (ISO8601, null=perpetual). AuthController.getSubscriptionState
  now delegates to the service (no drift between reported vs enforced).
- LOW/cosmetic: SelectPlanPage STATUS_ALERTS map has NO 'expired' (nor 'none'/'active'/'trial'/'exempt') entry
  → StatusAlert returns null for a lapsed-trial owner; page still renders "Renueva el plan" + PlanBuilder, no
  crash. Only the legacy Stripe statuses get colored banners.

## Manual payment add-package (ManualPaymentSection ↔ POST /tenant/{id}/subscription-payment) — point 4
- ALIGNED (backend UNCHANGED in this diff; prior slice, see tenant-billing-admin-seam.md). FE sends
  {method,paid_at,notes?,lines:[{package_key, amount(cents), interval?}]}. SubscriptionPaymentStoreRequest:
  method∈MANUAL_METHODS, lines.*.package_key exists:billing_package,key, lines.*.amount int min:1 max:1e9,
  interval nullable∈INTERVALS. package_key sourced from summary.available_packages (billing_package keys) →
  validates. amount = Math.round(pesos*100). TenantBillingPanel now feeds availablePackages + computed
  activeEntitlementKeys (entitlements filtered by package_key && isActiveEntitlement). ✓
- LOW (pre-existing): amount_pesos .positive() (not min .01) → sub-cent → round→0 → backend min:1 422 generic.

## ConfigurationController new 422 code (changeSubscriptionPlan)
- NEW {message, code:'PLAN_NOT_PAYABLE'} 422 when target plan !is_stripe. FE handleChangePlan
  (useSubscription.ts) swallows with a HARD-CODED generic toast ('No se pudo cambiar el plan.') — does NOT
  surface backend message nor read code. BUT ChangePlanPage useBillingPlans filters to p.is_stripe only, so
  the guard is UNREACHABLE from the UI → defensive backend guard, no runtime break. LOW.

## TenantForm (edit) simplification (not in the 4 points)
- Edit now submits only {name,status} via Orion PUT. Dropped legacy subscription_plan_id/tenant_payment_type_id
  selects + the 409 ACTIVE_SUBSCRIPTION force-confirm flow (billing moved to the billing modal / provision
  wizard). Sending fewer fields to Orion update is safe; the 409 path is now dead FE-side.
