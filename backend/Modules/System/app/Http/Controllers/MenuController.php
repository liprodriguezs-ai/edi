<?php

namespace Modules\System\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\System\Models\System;
use Modules\System\Services\ModuleTenantService;
use Modules\System\Services\SystemTenantService;

class MenuController extends Controller
{
    public function __construct(
        private SystemTenantService $systemTenantService,
        private ModuleTenantService $moduleTenantService,
    ) {}

    /**
     * Retorna la estructura de menu agrupada por sistemas,
     * filtrada por los sistemas activos del tenant y los permisos del usuario.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $abilities = $user->getPermissionsViaRoles()->pluck('name')->toArray();

        // Build the menu from the user's ACTIVE organization, not an arbitrary first pick.
        // A multi-organization user who selected B (via X-Tenant-Id / last_active_tenant_id)
        // had the menu computed from A — wrong enabled systems and per-tenant disabled
        // modules. Prefer the tenancy-initialized tenant ONLY when the user is actually a
        // member of it; otherwise fall back to their first membership. Super admins have
        // NO memberships, so this stays null and the Core-only branch below still fires —
        // even when they initialized a tenant via X-Tenant-Id for cross-tenant work.
        $userTenants = $user->tenants()->get();

        $tenant = null;
        if (tenancy()->initialized && tenant()) {
            $activeTenantId = (string) tenant()->getTenantKey();
            $tenant = $userTenants->first(
                fn ($candidate) => (string) $candidate->getTenantKey() === $activeTenantId
            );
        }
        $tenant ??= $userTenants->first();

        $disabledModuleIds = $tenant
            ? $this->moduleTenantService->disabledModuleIds($tenant)
            : [];

        $eagerModules = function ($q) use ($disabledModuleIds) {
            $q->where('module.visible', true)->orderByPivot('sort_order');

            if (! empty($disabledModuleIds)) {
                $q->whereNotIn('module.id', $disabledModuleIds);
            }
        };

        // Sin tenant (super admin): solo muestra el sistema Core
        if (! $tenant) {
            $systems = System::where('is_active', true)
                ->where('slug', 'core')
                ->with(['modules' => $eagerModules])
                ->orderBy('sort_order')
                ->get();
        } else {
            $systems = $this->systemTenantService->getActiveSystems($tenant);

            // Aplicar filtro de módulos deshabilitados por tenant (el servicio
            // no conoce el opt-out, así que filtramos acá sobre la colección cargada).
            if (! empty($disabledModuleIds)) {
                $disabledFlip = array_flip($disabledModuleIds);
                $systems->each(function ($system) use ($disabledFlip) {
                    $system->setRelation(
                        'modules',
                        $system->modules->reject(fn ($module) => isset($disabledFlip[$module->id]))->values()
                    );
                });
            }

            // Fallback: si el tenant aún no tiene sistemas configurados, mostrar todos excepto Core (Core es solo admin)
            if ($systems->isEmpty()) {
                $systems = System::where('is_active', true)
                    ->where('slug', '!=', 'core')
                    ->with(['modules' => $eagerModules])
                    ->orderBy('sort_order')
                    ->get();
            }
        }

        // permission_entity admite el formato 'verbo:entidad' (ej. 'update:unit_type')
        // para exigir esa habilidad exacta — así los catálogos administrables solo
        // aparecen a quien puede gestionarlos, aunque otros roles tengan read.
        $entityOf = fn (?string $permissionEntity): ?string => $permissionEntity && str_contains($permissionEntity, ':')
            ? explode(':', $permissionEntity, 2)[1]
            : $permissionEntity;

        $menu = $systems->map(function ($system) use ($abilities, $entityOf) {
            $modules = $system->modules
                ->filter(function ($module) use ($abilities) {
                    if (! $module->permission_entity) {
                        return true;
                    }

                    if (str_contains($module->permission_entity, ':')) {
                        [$verb, $entity] = explode(':', $module->permission_entity, 2);

                        return in_array("{$verb}.{$entity}", $abilities);
                    }

                    return in_array("read.{$module->permission_entity}", $abilities)
                        || in_array("report.{$module->permission_entity}", $abilities);
                })
                ->values();

            if ($modules->isEmpty()) {
                return null;
            }

            $grouped = collect($modules->groupBy(fn ($module) => $module->pivot->group_label ?? '__top__'));

            $items = collect();

            // Top-level modules primero (sin grupo)
            if ($grouped->has('__top__')) {
                foreach ($grouped->get('__top__') as $module) {
                    $items->push([
                        'type' => 'module',
                        'id' => $module->id,
                        'name' => $module->name,
                        'icon' => $module->icon,
                        'route' => $module->route,
                        'permission_entity' => $entityOf($module->permission_entity),
                        'group' => $module->group,
                    ]);
                }
            }

            // Grupos con sus módulos
            foreach ($grouped->except('__top__') as $groupLabel => $groupModules) {
                $firstModule = $groupModules->first();
                $items->push([
                    'type' => 'group',
                    'label' => $groupLabel,
                    'icon' => $firstModule->pivot->group_icon,
                    'modules' => $groupModules->map(fn ($module) => [
                        'id' => $module->id,
                        'name' => $module->name,
                        'icon' => $module->icon,
                        'route' => $module->route,
                        'permission_entity' => $entityOf($module->permission_entity),
                        'group' => $module->group,
                    ])->values(),
                ]);
            }

            return [
                'id' => $system->id,
                'name' => $system->name,
                'slug' => $system->slug,
                'icon' => $system->icon,
                'items' => $items,
            ];
        })->filter()->values();

        return response()->json(['data' => $menu]);
    }
}
