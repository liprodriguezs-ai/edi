<?php

namespace Modules\SubscriptionPlan\Console\Commands;

use Illuminate\Console\Command;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\Tenant\Models\Tenant;

/**
 * billing:recompute-entitlements — re-proyecta el acceso de los tenants.
 *
 * system_tenant / module_tenant son una PROYECCIÓN de los entitlements activos
 * (EntitlementService::recompute). Esa proyección solo se recalcula cuando un
 * entitlement cambia (grant, webhook, cron de expiración) — NO cuando cambia la
 * matriz misma. Tras editar el mapeo billable_module_system_module
 * (PlanModuleMatrixSeeder) o tras un reset de menú (ResetPermissionTableSeeder,
 * cuyo ModuleTableSeeder trunca `module` y arrastra el pivot por FK cascade),
 * los snapshots de todos los tenants quedan obsoletos hasta correr esto.
 *
 * recompute() es idempotente y apunta a un tenant EXPLÍCITO (conexión central,
 * sin TenantScope ambiente), así que no se necesita inicializar tenancy.
 */
class RecomputeEntitlementsCommand extends Command
{
    protected $signature = 'billing:recompute-entitlements
        {--tenant= : Recalcula solo este tenant (UUID)}';

    protected $description = 'Re-proyecta system_tenant/module_tenant desde los entitlements activos de cada tenant. Correr tras cambiar la matriz plan x modulo o tras un reset de menu.';

    public function handle(EntitlementService $entitlementService): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId !== null) {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                $this->error("Tenant {$tenantId} no encontrado.");

                return self::FAILURE;
            }

            $entitlementService->recompute($tenant);
            $this->info("Acceso re-proyectado para el tenant {$tenant->name}.");

            return self::SUCCESS;
        }

        $count = 0;

        foreach (Tenant::query()->cursor() as $tenant) {
            $entitlementService->recompute($tenant);
            $count++;
        }

        $this->info("Acceso re-proyectado para {$count} tenant(s).");

        return self::SUCCESS;
    }
}
