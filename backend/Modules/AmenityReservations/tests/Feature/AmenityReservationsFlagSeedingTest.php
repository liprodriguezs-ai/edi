<?php

namespace Modules\AmenityReservations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Setting\Models\Setting;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * The `amenity_reservations.enabled` per-tenant kill-switch defaults to false
 * for every newly seeded tenant (module stays dormant until the GA slice,
 * S17, explicitly flips it on). Mirrors the Collections\CollectionsDatabaseSeeder
 * test conventions.
 */
class AmenityReservationsFlagSeedingTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    public function test_flag_defaults_to_false_for_a_new_tenant(): void
    {
        $tenant = $this->makeTenant('Amenity Flag Cluster');
        $this->seedTenant($tenant);

        $value = $this->inTenant($tenant, fn () => Setting::getValue(AmenityReservationsSettings::ENABLED));

        $this->assertFalse($value, 'amenity_reservations.enabled must default to false for a new tenant.');
    }

    public function test_flag_seeding_is_idempotent_and_never_overwrites_an_admin_customized_value(): void
    {
        $tenant = $this->makeTenant('Amenity Flag Idempotent Cluster');
        $this->seedTenant($tenant);

        // Admin turns the flag on manually.
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        // Re-running the tenant seeder must not clobber the admin's choice.
        $this->seedTenant($tenant);

        $value = $this->inTenant($tenant, fn () => Setting::getValue(AmenityReservationsSettings::ENABLED));

        $this->assertTrue($value, 'Re-seeding must not overwrite an admin-customized flag value.');
        $count = $this->inTenant($tenant, fn () => Setting::where('slug', AmenityReservationsSettings::ENABLED)->count());
        $this->assertSame(1, $count, 'Re-seeding must not duplicate the setting row.');
    }

    public function test_two_tenants_have_independent_flag_values(): void
    {
        $tenantA = $this->makeTenant('Amenity Flag Tenant A');
        $tenantB = $this->makeTenant('Amenity Flag Tenant B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $this->inTenant($tenantA, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        $valueA = $this->inTenant($tenantA, fn () => Setting::getValue(AmenityReservationsSettings::ENABLED));
        $valueB = $this->inTenant($tenantB, fn () => Setting::getValue(AmenityReservationsSettings::ENABLED));

        $this->assertTrue($valueA);
        $this->assertFalse($valueB, 'Tenant B must be unaffected by Tenant A enabling the flag.');
    }
}
