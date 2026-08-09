<?php

namespace Modules\Tenant\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\System\Models\System;
use Modules\System\Services\SystemTenantService;
use Modules\Tenant\Models\Tenant;

class TenantSystemController extends Controller
{
    public function __construct(
        private SystemTenantService $systemTenantService,
    ) {
        // authz-02 — system assignment mutates ANY tenant by id (no tenant scope
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
     * Listar sistemas activos de un tenant.
     */
    public function index(string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);

        $systems = $tenant->systems()
            ->with('modules')
            ->orderBy('system.sort_order')
            ->get()
            ->map(fn ($system) => [
                'id' => $system->id,
                'name' => $system->name,
                'slug' => $system->slug,
                'icon' => $system->icon,
                'is_addon' => (bool) $system->pivot->is_addon,
                'modules_count' => $system->modules->count(),
            ]);

        return response()->json(['data' => $systems]);
    }

    /**
     * Agregar un sistema como addon manual.
     */
    public function addAddon(Request $request, string $tenantId): JsonResponse
    {
        $request->validate(['system_id' => 'required|uuid|exists:system,id']);

        $tenant = Tenant::findOrFail($tenantId);
        $system = System::findOrFail($request->system_id);

        $this->systemTenantService->addAddon($tenant, $system);

        return response()->json(['message' => "Sistema '{$system->name}' agregado como addon"]);
    }

    /**
     * Quitar un sistema addon del tenant.
     */
    public function removeAddon(string $tenantId, string $systemId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $system = System::findOrFail($systemId);

        $removed = $this->systemTenantService->removeAddon($tenant, $system);

        if (! $removed) {
            return response()->json(
                ['message' => 'Solo se pueden quitar sistemas addon, no los del plan'],
                422
            );
        }

        return response()->json(['message' => "Sistema '{$system->name}' removido"]);
    }
}
