<?php

namespace Modules\Package\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Package\Services\PackageService;
use Modules\Tenant\Models\Tenant;

/**
 * ProcessPackageRetentionCommand — daily per-tenant retention cron.
 *
 * THIN command (mirrors RefreshChargeStatusesCommand): ALL retention business logic
 * lives in PackageService::processRetention (design §6.3). For each tenant it runs the
 * service inside the tenant context and emits a per-tenant summary line.
 *
 * Per-tenant fault isolation: a failure in one tenant (e.g. returnPackage's abort(422)
 * on a concurrent status change) is logged and skipped so it never aborts the whole cron
 * and starves every tenant that follows in iteration order.
 */
class ProcessPackageRetentionCommand extends Command
{
    protected $signature = 'package:process-retention';

    protected $description = 'Procesa recordatorios y devoluciones de paquetes no retirados por tenant';

    public function handle(): int
    {
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $label = $tenant->name ?? $tenant->id;

            try {
                // Resolve a FRESH PackageService inside the tenant context so its per-instance
                // setting cache can never leak across tenants.
                $tenant->run(function () use ($tenant, $label): void {
                    ['reminded' => $reminded, 'returned' => $returned] = app(PackageService::class)->processRetention($tenant);
                    $this->info("[{$label}] {$reminded} recordatorio(s) enviado(s), {$returned} paquete(s) devuelto(s).");
                });
            } catch (\Throwable $e) {
                Log::error('package:process-retention failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("[{$label}] Error: {$e->getMessage()}");
            }
        }

        $this->info('package:process-retention completado.');

        return self::SUCCESS;
    }
}
