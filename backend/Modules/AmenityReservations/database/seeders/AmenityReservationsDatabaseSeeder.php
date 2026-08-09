<?php

namespace Modules\AmenityReservations\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Setting\Models\Setting;

/**
 * AmenityReservationsDatabaseSeeder — seeds the per-tenant kill-switch that
 * gates the whole AmenityReservations module for the CURRENT tenant:
 *   - amenity_reservations.enabled (default false)
 *
 * Must run inside tenant context so BelongsToTenant stamps tenant_id. Uses
 * a withTrashed lookup (NOT firstOrCreate) so an admin's customized value is
 * NEVER overwritten when this runs again, and a soft-deleted row (which still
 * occupies the non-partial unique(tenant_id, slug) index) is restored instead
 * of colliding on re-seed. Mirrors Collections\Database\Seeders\CollectionsDatabaseSeeder.
 */
class AmenityReservationsDatabaseSeeder extends Seeder
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
            AmenityReservationsSettings::ENABLED => AmenityReservationsSettings::DEFAULT_ENABLED,
        ];

        foreach ($defaults as $slug => $default) {
            $existing = Setting::withTrashed()->where('slug', $slug)->first();

            if ($existing === null) {
                Setting::create([
                    'name' => $slug,
                    'slug' => $slug,
                    'value' => $default,
                    'type' => Setting::TYPE_BOOLEAN,
                ]);
            } elseif ($existing->trashed()) {
                $existing->restore();
            }
        }
    }
}
