<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;

/**
 * SINGLE source of truth for "which tenant does THIS request operate on".
 *
 * Both InitializeTenancyFromUser (which sets the TenantScope the controllers run
 * under) AND the subscription enforcement path (EnsureSubscriptionActive ->
 * SubscriptionStateService) MUST resolve the request's tenant through this class,
 * so the tenant that gets ENFORCED is ALWAYS the exact same tenant the request
 * ends up OPERATING on. Any divergence here reopens the X-Tenant-Id enforcement
 * bypass: a user who belongs to both an ACTIVE cluster A and a BLOCKED cluster B
 * could get enforcement evaluated against A (via last_active) while the controller
 * serves B (via the X-Tenant-Id header).
 *
 * Pure resolution — NO side effects. It never persists last_active_tenant_id; that
 * is a separate concern owned by InitializeTenancyFromUser, and the enforcement
 * gate must never mutate state. The header id is ALWAYS validated against the
 * user's own memberships for regular users; platform roles (super admin / lawyer)
 * may target any existing tenant.
 */
class ActiveTenantResolver
{
    /**
     * Resolve the tenant the request will operate on, or null when it runs
     * unscoped (platform role without a header) or the user has no membership.
     */
    public function resolve(Request $request, User $user): ?Tenant
    {
        if ($user->hasRole([Role::SLUG_SUPER_ADMIN, Role::SLUG_LAWYER])) {
            return $this->resolveForPlatformRole($request);
        }

        return $this->resolveForRegularUser($request, $user);
    }

    /**
     * Platform roles operate cross-tenant: an X-Tenant-Id header scopes them to
     * that tenant (no membership requirement); without it they run unscoped.
     * Mirrors InitializeTenancyFromUser's platform branch exactly.
     */
    private function resolveForPlatformRole(Request $request): ?Tenant
    {
        $tenantId = $request->header('X-Tenant-Id');

        if ($tenantId && ($tenant = Tenant::find($tenantId))) {
            return $tenant;
        }

        return null;
    }

    /**
     * Regular users resolve, in priority order:
     *   1. X-Tenant-Id header — ONLY if the user is a member of that tenant.
     *   2. last_active_tenant_id — if the user is still a member.
     *   3. First tenant the user belongs to.
     */
    private function resolveForRegularUser(Request $request, User $user): ?Tenant
    {
        $tenants = $user->tenants()->get()->keyBy(fn (Tenant $tenant): string => (string) $tenant->getKey());

        if ($tenants->isEmpty()) {
            return null;
        }

        $requestedTenantId = $request->header('X-Tenant-Id');
        if ($requestedTenantId && $tenants->has((string) $requestedTenantId)) {
            return $tenants->get((string) $requestedTenantId);
        }

        $lastId = $user->last_active_tenant_id ? (string) $user->last_active_tenant_id : null;
        if ($lastId && $tenants->has($lastId)) {
            return $tenants->get($lastId);
        }

        return $tenants->first();
    }
}
