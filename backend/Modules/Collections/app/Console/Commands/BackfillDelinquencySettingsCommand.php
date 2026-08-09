<?php

namespace Modules\Collections\Console\Commands;

use Illuminate\Console\Command;
use Modules\Collections\Database\Seeders\CollectionsDatabaseSeeder;
use Modules\Tenant\Models\Tenant;

/**
 * BackfillDelinquencySettingsCommand — one-shot backfill for existing tenants.
 *
 * Runs CollectionsDatabaseSeeder inside each tenant's context so clusters created
 * before the delinquency.* settings existed get the six new rows. The seeder
 * looks up each slug withTrashed() and only creates/restores when absent, so any
 * already-customized value (or any of the pre-existing collections.* settings)
 * is preserved and re-running is safe.
 */
class BackfillDelinquencySettingsCommand extends Command
{
    protected $signature = 'delinquency:backfill-settings';

    protected $description = 'Backfill the six delinquency.* restriction settings for all existing tenants.';

    public function handle(): int
    {
        foreach (Tenant::all() as $tenant) {
            $tenant->run(fn () => (new CollectionsDatabaseSeeder)->run());

            $label = $tenant->name ?? $tenant->id;
            $this->info("[{$label}] Delinquency settings backfilled.");
        }

        $this->info('delinquency:backfill-settings completed.');

        return self::SUCCESS;
    }
}
