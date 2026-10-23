<?php

namespace App\Http\Middleware;

use App\Services\ActiveTenantResolver;
use Closure;
use Illuminate\Http\Request;
use Modules\Company\Services\CompanyService;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Initializes tenancy from the authenticated user (tenant_id or first tenant).
 *
 * SINGLE-DATABASE mode: this only sets the CURRENT tenant, which drives the
 * TenantScope global scope (BelongsToTenant). There is no database switching
 * and no per-tenant migration — all data lives in one shared database scoped
 * by a tenant_id column. Must run AFTER auth:sanctum (it does not authenticate).
 *
 * Tenant resolution is delegated to the shared ActiveTenantResolver so that the
 * subscription enforcement gate (EnsureSubscriptionActive) evaluates the EXACT
 * same tenant this middleware initializes — no divergence, no X-Tenant-Id bypass.
 * The header id is ALWAYS validated against the user's own tenants for regular
 * users; platform roles may target any tenant. This middleware additionally
 * persists an explicit header selection (multi-organization switching), which the
 * pure resolver deliberately does not do.
 */
class InitializeTenancyFromUser
{
    public function __construct(private readonly ActiveTenantResolver $tenantResolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $isPlatformRole = $user->hasRole([Role::SLUG_SUPER_ADMIN, Role::SLUG_LAWYER]);

        $tenant = $this->tenantResolver->resolve($request, $user);

        // Deny, rather than continue unscoped, when a regular user has no
        // organization. Stancl's TenantScope adds NO condition while tenancy is
        // uninitialized -- the right call for console and seeders, but for a
        // logged-in user it means every tenant-scoped query runs unfiltered and
        // returns (and lets them mutate) every organization's rows.
        //
        // A user reaches this state without doing anything unusual: created
        // without an organization, or left without one when an organization is
        // deleted and their user_tenant rows cascade.
        //
        // Default-deny: a route is reachable without an organization only if it is
        // listed as central below. New routes are therefore denied until someone
        // decides otherwise, which is the safe direction for the mistake to run in.
        if (! $tenant && ! $isPlatformRole && ! $this->isCentralRoute($request)) {
            abort(403, 'Tu usuario no pertenece a ninguna organización. Pide a un administrador que te asigne una.');
        }

        if ($tenant) {
            // Regular users: persist an explicit X-Tenant-Id pick so the next
            // request defaults to the same organization. Platform roles never persist.
            if (! $isPlatformRole) {
                $this->persistActiveTenantSelection($request, $user, $tenant);
            }

            tenancy()->initialize($tenant);

            // Only regular users get the main-company guarantee; platform roles
            // operate cross-tenant and must not mutate the targeted organization.
            if (! $isPlatformRole && tenancy()->initialized) {
                try {
                    app(CompanyService::class)->ensureMainCompanyForTenant(tenancy()->tenant);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $next($request);
    }

    /**
     * Routes that serve CENTRAL data and therefore work without an organization.
     *
     * Everything else in this middleware group reads or writes tenant-scoped data,
     * where continuing without an organization means running unscoped. Each entry
     * below is here because the route is central by design, not by convenience:
     *
     *   auth/*                        the session itself -- without it a user in
     *                                 this state could not even sign out.
     *   menu                          documented to return only the Core system
     *                                 when the user has no organization.
     *   user/notification-preferences its controller states "central per-user
     *                                 resource (no tenant required)".
     *   tenant, tenant/*              platform organization management, which by
     *                                 definition runs before an organization
     *                                 exists. Still gated by its own permission.
     */
    private function isCentralRoute(Request $request): bool
    {
        return $request->is(
            'api/auth/*',
            'api/menu',
            'api/user/notification-preferences',
            'api/tenant',
            'api/tenant/*',
        );
    }

    /**
     * Persist an EXPLICIT X-Tenant-Id selection to last_active_tenant_id. Only
     * fires when the resolved tenant came from a valid header pick (the resolver
     * already validated membership); other paths (last_active / first tenant)
     * leave last_active_tenant_id untouched, preserving the previous behaviour.
     */
    private function persistActiveTenantSelection(Request $request, $user, Tenant $tenant): void
    {
        $requestedTenantId = $request->header('X-Tenant-Id');

        if ($requestedTenantId && (string) $requestedTenantId === (string) $tenant->id) {
            $user->withoutEvents(function () use ($user, $tenant): void {
                $user->updateQuietly(['last_active_tenant_id' => $tenant->id]);
            });
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
