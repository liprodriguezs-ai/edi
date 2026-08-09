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
 * persists an explicit header selection (multi-cluster switching), which the
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

        if ($tenant) {
            // Regular users: persist an explicit X-Tenant-Id pick so the next
            // request defaults to the same cluster. Platform roles never persist.
            if (! $isPlatformRole) {
                $this->persistActiveTenantSelection($request, $user, $tenant);
            }

            tenancy()->initialize($tenant);

            // Only regular users get the main-company guarantee; platform roles
            // operate cross-tenant and must not mutate the targeted cluster.
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
