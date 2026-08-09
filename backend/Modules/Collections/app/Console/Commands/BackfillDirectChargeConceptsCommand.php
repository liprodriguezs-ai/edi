<?php

namespace Modules\Collections\Console\Commands;

use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Console\Command;
use Modules\Tenant\Models\Tenant;

/**
 * BackfillDirectChargeConceptsCommand — one-shot, idempotent backfill for existing tenants.
 *
 * TenantCatalogSeeder::seedDirectChargeConcepts() only runs on TenantCreated, so clusters
 * created before the cobro-directo feature (2026-07-03) have neither the protected
 * "Servicios / Eventuales" category nor the generic technical "OTHER" concept. Without
 * "OTHER", DirectChargeService::resolveOtherConceptId() 422s every free-text direct charge.
 *
 * This re-runs the same seeding logic inside each tenant's context. Every write is
 * firstOrCreate/saveQuietly, so any admin customization is preserved and re-running is safe.
 */
class BackfillDirectChargeConceptsCommand extends Command
{
    protected $signature = 'collections:backfill-direct-charge-concepts';

    protected $description = 'Backfill the direct-charge concept category + the protected technical OTHER concept for all existing tenants.';

    public function handle(): int
    {
        foreach (Tenant::all() as $tenant) {
            $tenant->run(fn () => (new TenantCatalogSeeder)->seedDirectChargeConcepts());

            $label = $tenant->name ?? $tenant->id;
            $this->info("[{$label}] Direct-charge concepts backfilled.");
        }

        $this->info('collections:backfill-direct-charge-concepts completed.');

        return self::SUCCESS;
    }
}
