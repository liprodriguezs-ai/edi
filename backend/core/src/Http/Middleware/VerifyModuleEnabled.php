<?php

namespace QCH\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\System\Services\ModuleTenantService;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a endpoints cuyo modulo esta deshabilitado para el tenant activo.
 *
 * Resolucion:
 *  - Se recibe el permission_entity del modulo como parametro (igual al que usa Orion $keyForPermission).
 *  - Semantica opt-out: si no existe fila en module_tenant o enabled=true, se permite. Solo fila con enabled=false bloquea.
 *  - Super admin / lawyer: siempre permitidos (operan cross-tenant).
 *  - Sin tenant inicializado: se permite (endpoints centrales).
 */
class VerifyModuleEnabled
{
    public function __construct(
        private ModuleTenantService $moduleTenantService,
    ) {}

    public function handle(Request $request, Closure $next, string $permissionEntity): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->hasRole([Role::SLUG_SUPER_ADMIN, Role::SLUG_LAWYER])) {
            return $next($request);
        }

        $tenant = $this->resolveTenant($user);

        if (! $tenant) {
            return $next($request);
        }

        if ($this->moduleTenantService->isEnabledByPermissionEntity($tenant, $permissionEntity)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Módulo no habilitado para este tenant',
            'code' => 'MODULE_DISABLED',
        ], 403);
    }

    private function resolveTenant($user): ?Tenant
    {
        if (tenancy()->initialized && tenancy()->tenant instanceof Tenant) {
            return tenancy()->tenant;
        }

        return $user->tenants()->first();
    }
}
