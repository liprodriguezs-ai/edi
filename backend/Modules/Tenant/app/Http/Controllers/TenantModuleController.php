<?php

namespace Modules\Tenant\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Module\Models\Module;
use Modules\System\Services\ModuleTenantService;
use Modules\Tenant\Models\Tenant;

class TenantModuleController extends Controller
{
    public function __construct(
        private ModuleTenantService $moduleTenantService,
    ) {
        // authz-02 — module assignment mutates ANY tenant by id (no tenant scope
        // applies here). update.tenant is super-admin-exclusive in the seeded
        // matrix, making this gate platform-admin-only in practice.
        $this->middleware(function ($request, $next) {
            $user = auth()->guard('sanctum')->user();

            if (! $user || ! ($user->tokenCan('update.tenant') || $user->tokenCan('*'))) {
                throw new AuthorizationException('Unauthorized action.');
            }

            return $next($request);
        });
    }

    /**
     * Listar modulos del tenant agrupados por sistema activo.
     * Incluye el estado 'enabled' resuelto (opt-out: ausencia = habilitado).
     */
    public function index(string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $disabledIds = array_flip($this->moduleTenantService->disabledModuleIds($tenant));

        $systems = $tenant->active_systems()
            ->with(['modules' => fn ($q) => $q->where('module.visible', true)->orderByPivot('sort_order')])
            ->orderBy('system.sort_order')
            ->get();

        $data = $systems->map(fn ($system) => [
            'id' => $system->id,
            'name' => $system->name,
            'slug' => $system->slug,
            'icon' => $system->icon,
            'modules' => $system->modules->map(fn ($module) => [
                'id' => $module->id,
                'name' => $module->name,
                'icon' => $module->icon,
                'route' => $module->route,
                'permission_entity' => $module->permission_entity,
                'enabled' => ! isset($disabledIds[$module->id]),
            ])->values(),
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Toggle de un modulo para el tenant.
     */
    public function toggle(Request $request, string $tenantId, string $moduleId): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $tenant = Tenant::findOrFail($tenantId);
        $module = Module::findOrFail($moduleId);

        $this->moduleTenantService->setEnabled($tenant, $module, (bool) $validated['enabled']);

        return response()->json([
            'message' => $validated['enabled']
                ? "Modulo '{$module->name}' habilitado"
                : "Modulo '{$module->name}' deshabilitado",
        ]);
    }

    /**
     * Sincronizacion masiva.
     */
    public function sync(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'modules' => 'required|array',
            'modules.*.module_id' => 'required|uuid|exists:module,id',
            'modules.*.enabled' => 'required|boolean',
        ]);

        $tenant = Tenant::findOrFail($tenantId);

        $this->moduleTenantService->syncBulk($tenant, $validated['modules']);

        return response()->json(['message' => 'Modulos sincronizados']);
    }
}
