---
name: stripe-webhook-subscription
description: Stripe webhook + Cashier subscription flow tenancy isolation — webhook is unauthenticated/no-scope; tenant resolved BY ID from metadata. SAFE pattern + the signature-secret dependency.
metadata:
  type: pattern
---

# Stripe webhook + subscription flow (tenancy isolation)

Reviewed 2026-06-21. Files: `app/Listeners/StripeWebhookListener.php`,
`app/Http/Controllers/StripeWebhookController.php`, `routes/api.php`,
`Modules/Configuration/app/Http/Controllers/ConfigurationController.php`.

## Verdict: SAFE for tenant isolation. No cross-tenant leak / wrong-tenant mutation.

## Key facts (why it's safe)
- **`Tenant` is central** (`extends Stancl BaseTenant`, NO `BelongsToTenant`/`TenantScope`). Writes by id are correct with NO tenancy initialized. Listener NEVER calls `tenancy()->initialize()`.
- **`SystemTenantService::syncFromPlan($tenant)`** operates strictly on the passed instance: `$tenant->systems()` (pivot `system_tenant` filtered by `tenant_id`) + `$tenant->subscription_plan`. Does NOT depend on global TenantScope. Safe to call unscoped.
- **Listener resolution** is by `metadata.tenant_id` → `Tenant::find($id)` (single row). Forged/missing metadata → `Tenant::find(null/garbage)` returns null → no-op (logged). No global UPDATE path; no `DB::` raw; no `Tenant::all()`/mass update. A forged id can only hit a real tenant id — mitigated by signature verification (see dependency below).
- **Fallback `resolveTenantFromCustomer`**: `User::where('stripe_id',...)->first()` (UserTenantScope inert when tenancy uninitialized — it early-returns at `!tenancy()->initialized`). Then gated by `$user->hasRole(Role::SLUG_ACCOUNT_OWNER)` AND `$user->tenants()->count() === 1` → returns null when ambiguous. Multi-tenant owner can NOT mutate arbitrary tenant. `tenants()` is `belongsToMany` via `user_tenant` pivot (correctly filtered to that user).
- **Spatie `'teams' => false`** (config/permission.php) → roles global → `hasRole('account.owner')` works in unauthenticated webhook context.
- **ConfigurationController** `$tenant->users()->role(...)` → `users()` is `belongsToMany(User, 'user_tenant', tenant_id, user_id)` → filtered to that tenant's pivot rows; `role()` is Spatie name scope (`SLUG_ACCOUNT_OWNER='account.owner'`). Under an authed request UserTenantScope also adds the current-tenant filter (redundant, == $tenant). Correct.
- **`Unit::query()->count()`** in getSubscription: Unit uses `BelongsToTenant` → auto-scoped by TenantScope to current tenant. Correct.
- **No duplicate webhook route**: `Cashier::ignoreRoutes()` IS called in AppServiceProvider (line 20). Only endpoint is manual `/api/stripe/webhook`. Empty `StripeWebhookController extends CashierWebhookController` inherits signature middleware.

## CRITICAL DEPENDENCY (not a code bug, a config/ops requirement)
Signature verification (`VerifyWebhookSignature`) is attached by Cashier's
WebhookController constructor ONLY when `config('cashier.webhook.secret')` (env
`STRIPE_WEBHOOK_SECRET`) is set. If that env var is missing in prod, the route is
UNSIGNED → anyone can POST forged `metadata.tenant_id` and flip ANY tenant's
status (activo/bloqueado/pendiente_pago) + resync systems = wrong-tenant mutation.
There is NO `config/cashier.php` published (package defaults). This must be
enforced at deploy time. Recommend: fail-closed guard or a config check in CI.

## Test contract
`tests/Feature/Configuration/StripeCheckoutTest.php` is rigorous: `dispatchWebhook`
calls `tenancy()->end()` first to mirror the real no-scope context before invoking
the listener. Covers checkout-completed activate, invoice-failed via customer
fallback, subscription-deleted block, subscription-updated past_due.
