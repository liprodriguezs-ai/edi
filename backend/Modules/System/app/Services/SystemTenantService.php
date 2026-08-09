<?php

namespace Modules\System\Services;

use Illuminate\Support\Collection;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;

class SystemTenantService
{
    /**
     * Agrega un sistema como addon manual (super admin).
     */
    public function addAddon(Tenant $tenant, System $system): void
    {
        if ($tenant->systems()->where('system.id', $system->id)->exists()) {
            return;
        }

        $tenant->systems()->attach($system->id, ['is_addon' => true]);
    }

    /**
     * Quita un sistema addon manual. No permite quitar sistemas del plan.
     */
    public function removeAddon(Tenant $tenant, System $system): bool
    {
        $pivot = $tenant->systems()->where('system.id', $system->id)->first();

        if (! $pivot || ! $pivot->pivot->is_addon) {
            return false;
        }

        $tenant->systems()->detach($system->id);

        return true;
    }

    /**
     * Retorna los sistemas activos del tenant con sus modulos.
     */
    public function getActiveSystems(Tenant $tenant): Collection
    {
        return $tenant->active_systems()
            ->with(['modules' => function ($query) {
                $query->where('module.visible', true)
                    ->orderByPivot('sort_order');
            }])
            ->orderBy('system.sort_order')
            ->get();
    }
}
