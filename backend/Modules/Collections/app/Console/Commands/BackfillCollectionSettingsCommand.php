<?php

namespace Modules\Collections\Console\Commands;

use Illuminate\Console\Command;
use Modules\Collections\Database\Seeders\CollectionsDatabaseSeeder;
use Modules\Tenant\Models\Tenant;

/**
 * BackfillCollectionSettingsCommand — one-shot backfill for existing tenants.
 *
 * Runs CollectionsDatabaseSeeder inside each tenant's context so clusters created
 * before these settings existed get the GLOBAL collection settings
 * (charge_generation_day, due_soon_days, grace_days). The seeder uses firstOrCreate, so any
 * already-customized value is preserved and re-running is safe.
 */
class BackfillCollectionSettingsCommand extends Command
{
    protected $signature = 'collections:backfill-settings';

    protected $description = 'Backfill the global collection settings (generation day, due-soon days, grace days) for all existing tenants.';

    public function handle(): int
    {
        foreach (Tenant::all() as $tenant) {
            $tenant->run(fn () => (new CollectionsDatabaseSeeder)->run());

            $label = $tenant->name ?? $tenant->id;
            $this->info("[{$label}] Collection settings backfilled.");
        }

        $this->info('collections:backfill-settings completed.');

        return self::SUCCESS;
    }
}
