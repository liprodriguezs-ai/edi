<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ActiveTenantResolver;
use App\Services\SubscriptionStateService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns the organization's `is_blocked` state into a REAL server-side boundary.
 *
 * Before this, is_blocked was computed only for the auth responses and enforced
 * client-side (SubscriptionGuard). A lapsed trial still got 200 OK from every API
 * endpoint. This middleware denies (403) any authenticated request from a blocked
 * organization — EXCEPT the routes the owner needs to recover (auth + pay-flow).
 *
 * Placement: appended to the `api` middleware group, so EVERY api route is covered
 * with zero per-route holes. It resolves the authenticated user through the sanctum
 * guard itself (rather than depending on the route's auth:sanctum having run) and
 * resolves the organization + state through SubscriptionStateService — the same source
 * of truth AuthController uses — so it needs neither initializeTenancy to have run
 * nor any duplicated logic.
 *
 * Never encircles the owner:
 *   - config('billing.enforce_subscription') = false is a global kill-switch.
 *   - unauthenticated requests pass through (public routes; auth 401s downstream).
 *   - super admin / lawyer are never organization-blocked (handled in the service).
 *   - the ALLOWLIST keeps auth + the entire pay-flow reachable while blocked.
 */
class EnsureSubscriptionActive
{
    /**
     * Path patterns (relative to the app root, so including the `api/` prefix) that
     * a BLOCKED organization must still reach — otherwise the owner could never pay to
     * unblock. `$request->is()` matches these with `*` wildcards.
     *
     * @var array<int, string>
     */
    private const ALLOWLIST = [
        // Auth + session lifecycle: /auth/me (reads is_blocked), logout, refresh,
        // login, register, forgot/reset password, accept-invitation, terms, etc.
        'api/auth/*',
        // Multi-item Stripe checkout + the public catalog/quoter used to pick a plan.
        'api/billing/*',
        // Self-service subscription screen: read state, change plan, checkout,
        // billing portal, cancel, resume.
        'api/config/subscription',
        'api/config/subscription/*',
        // Onboarding + app bootstrap (mostly public; kept explicit for safety).
        'api/onboarding',
        'api/onboarding/*',
        'api/app-config',
        // Stripe webhook (unauthenticated; belt-and-suspenders).
        'api/stripe/*',
        // Public tracking beacon (Facebook CAPI / GA4 MP) — same public-endpoint
        // class as billing/catalog above. An authenticated user of a blocked
        // tenant must still be able to fire InitiateCheckout/etc. on their way to
        // paying; unauthenticated (anonymous) requests already pass through
        // untouched since this middleware only enforces for a resolved user.
        'api/track',
    ];

    public function __construct(
        private SubscriptionStateService $subscriptionState,
        private ActiveTenantResolver $tenantResolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('billing.enforce_subscription')) {
            return $next($request);
        }

        // Allowlist BEFORE touching auth: public pay-flow / catalog / stripe-webhook
        // routes must not pay for sanctum token resolution or a state lookup, and
        // some (webhook) are legitimately unauthenticated. (B1)
        if ($this->isAllowlisted($request)) {
            return $next($request);
        }

        // Resolve the authenticated user via the sanctum guard. Works whether or
        // not the route's auth:sanctum middleware has already run; null = public or
        // invalid token → let it through (auth will 401 downstream if required).
        $user = $request->user() ?? $request->user('sanctum');

        if ($user === null) {
            return $next($request);
        }

        // Enforce against the EXACT tenant the request will operate on. The shared
        // resolver honors X-Tenant-Id identically to InitializeTenancyFromUser, so
        // a multi-membership user can no longer point enforcement at an ACTIVE
        // organization while the controller serves a BLOCKED one. (A1)
        $tenant = $this->tenantResolver->resolve($request, $user);

        // TODO(infra-cost): the state is recomputed (~3-5 queries) on every
        // non-allowlisted request. A per-tenant cache (Cache::remember(
        // "subscription_state:{$tenant->id}", <=60s, ...)) would cut that, BUT it
        // is deliberately NOT implemented yet because airtight invalidation cannot
        // be guaranteed here without risking a PAID user staying blocked on stale
        // state — the cardinal sin. Blockers, all of which must invalidate BEFORE
        // caching is safe: (a) EntitlementService::grant/recompute, direct
        // TenantEntitlement writes, StripeWebhookListener (checkout/cancel/resume/
        // deleted), SubscriptionPaymentService::recordManual, ExpireManualEntitlements
        // command; AND (b) entitlement expiry is time-based (expires_at) — a TTL cache
        // would over-serve access past the exact expiry instant. Until (a) is provably
        // covered, correctness > cost: no cache.
        $gate = $this->subscriptionState->gateFor($user, $tenant);

        if ($gate->isBlocked) {
            return response()->json([
                'message' => 'Tu suscripción está vencida o inactiva. Renueva para continuar.',
                'code' => 'SUBSCRIPTION_INACTIVE',
            ], 403);
        }

        return $next($request);
    }

    private function isAllowlisted(Request $request): bool
    {
        return $request->is(...self::ALLOWLIST);
    }
}
