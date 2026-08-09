<?php

namespace Modules\Collections\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Collections\Support\CollectionSettings;
use Modules\Collections\Support\DelinquencySettings;
use Modules\Setting\Models\Setting;

/**
 * CollectionsDatabaseSeeder — seeds the GLOBAL per-tenant collection settings
 * that drive the charge lifecycle for the CURRENT tenant:
 *   - collections.charge_generation_day (default 1)
 *   - collections.due_soon_days        (default 7)
 *   - collections.grace_days           (default 5)
 *   - delinquency.*                    (six slugs, see DelinquencySettings — all
 *                                        ship DISABLED, see class docblock)
 *
 * Must run inside tenant context so BelongsToTenant stamps tenant_id. Uses
 * firstOrCreate (NOT updateOrCreate) so an admin's customized value is NEVER
 * overwritten when this runs again (e.g. via collections:backfill-settings).
 */
class CollectionsDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        $defaults = [
            CollectionSettings::GENERATION_DAY => CollectionSettings::DEFAULT_GENERATION_DAY,
            CollectionSettings::DUE_SOON_DAYS => CollectionSettings::DEFAULT_DUE_SOON_DAYS,
            CollectionSettings::GRACE_DAYS => CollectionSettings::DEFAULT_GRACE_DAYS,
            ...DelinquencySettings::all(),
        ];

        foreach ($defaults as $slug => $default) {
            // A soft-deleted row still occupies the (tenant_id, slug) unique key
            // (the index is NOT partial), so a plain firstOrCreate would violate
            // it. Look WITH trashed: create when absent, restore when trashed,
            // and leave an active admin-customized value untouched.
            $existing = Setting::withTrashed()->where('slug', $slug)->first();

            if ($existing === null) {
                Setting::create([
                    'name' => $slug,
                    'slug' => $slug,
                    'value' => $default,
                    'type' => match (true) {
                        is_bool($default) => Setting::TYPE_BOOLEAN,
                        is_int($default) => Setting::TYPE_INTEGER,
                        default => Setting::TYPE_STRING,
                    },
                ]);
            } elseif ($existing->trashed()) {
                $existing->restore();
            }
        }
    }
}
