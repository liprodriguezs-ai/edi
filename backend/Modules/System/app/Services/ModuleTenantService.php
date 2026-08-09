<?php

namespace Modules\System\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Module\Models\Module;
use Modules\Tenant\Models\Tenant;

class ModuleTenantService
{
    /**
     * El pivot module_tenant vive SIEMPRE en la base central, no en la del tenant.
     * Este helper fuerza la conexion central para que el servicio funcione aun
     * cuando el middleware de tenancy haya cambiado la conexion por defecto.
     * Usamos la clave configurada en tenancy.database.central_connection con
     * fallback al valor original de database.default capturado en config/tenancy.php.
     */
    private function centralConnection(): string
    {
        return config('tenancy.database.central_connection', 'pgsql');
    }

    private function table(): Builder
    {
        return DB::connection($this->centralConnection())->table('module_tenant');
    }

    /**
     * Semantica OPT-OUT: si no hay fila en el pivot o la fila tiene enabled=true,
     * el modulo esta habilitado. Solo las filas con enabled=false deshabilitan.
     */
    public function isEnabled(Tenant $tenant, Module $module): bool
    {
        $row = $this->table()
            ->where('tenant_id', $tenant->id)
            ->where('module_id', $module->id)
            ->first();

        if (! $row) {
            return true;
        }

        return (bool) $row->enabled;
    }

    /**
     * Resolver modulo por permission_entity (matches Orion $keyForPermission).
     *
     * Un permission_entity puede estar respaldado por MAS de una fila en `module`
     * (p.ej. la 'Cobros' sembrada + un duplicado). setEnabled()/isEnabled() operan
     * sobre un module_id concreto, asi que resolver con un unico ->first() aqui es
     * ambiguo: podria devolver un modulo distinto al que lleva la marca enabled=false
     * y, por opt-out, dejar pasar la peticion. Resolvemos por TODAS las filas que
     * comparten el permission_entity y aplicamos opt-out fail-safe: el modulo esta
     * deshabilitado si CUALQUIERA de esas filas tiene una fila enabled=false para el
     * tenant. Asi la resolucion es consistente sin importar que duplicado lleve la marca.
     */
    public function isEnabledByPermissionEntity(Tenant $tenant, string $permissionEntity): bool
    {
        $moduleIds = Module::on($this->centralConnection())
            ->where('permission_entity', $permissionEntity)
            ->pluck('id');

        if ($moduleIds->isEmpty()) {
            return true;
        }

        $hasDisabled = $this->table()
            ->where('tenant_id', $tenant->id)
            ->whereIn('module_id', $moduleIds->all())
            ->where('enabled', false)
            ->exists();

        return ! $hasDisabled;
    }

    public function setEnabled(Tenant $tenant, Module $module, bool $enabled): void
    {
        $this->table()->updateOrInsert(
            ['tenant_id' => $tenant->id, 'module_id' => $module->id],
            ['enabled' => $enabled, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * @param  array<int, array{module_id: string, enabled: bool}>  $entries
     */
    public function syncBulk(Tenant $tenant, array $entries): void
    {
        $central = $this->centralConnection();

        DB::connection($central)->transaction(function () use ($central, $tenant, $entries) {
            foreach ($entries as $entry) {
                $module = Module::on($central)->find($entry['module_id']);

                if (! $module) {
                    continue;
                }

                $this->setEnabled($tenant, $module, (bool) $entry['enabled']);
            }
        });
    }

    /**
     * IDs de modulos deshabilitados explicitamente para este tenant.
     *
     * @return array<int, string>
     */
    public function disabledModuleIds(Tenant $tenant): array
    {
        return $this->table()
            ->where('tenant_id', $tenant->id)
            ->where('enabled', false)
            ->pluck('module_id')
            ->all();
    }
}
