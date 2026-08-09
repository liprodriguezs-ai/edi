<?php

namespace Modules\AmenityReservations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S4 — two independent gates on the amenity endpoints:
 *  1. Permission scoping (token abilities via TraitController::resourceAbilityMap):
 *     a resident holding only `read.amenity` can list/read but gets 403 on
 *     create/update.
 *  2. The `amenity_reservations.enabled` per-tenant kill-switch
 *     (EnsureAmenityReservationsEnabled): with the flag OFF (its S1 default)
 *     every amenity route is blocked even for an otherwise-privileged admin;
 *     flipping it ON unblocks them.
 */
final class AmenityPermissionAndFlagGateTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function enableFlag(Tenant $tenant): void
    {
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });
    }

    private function amenityPayload(): array
    {
        return [
            'name' => 'Salón de eventos '.uniqid(),
            'capacity' => 40,
            'available_weekdays' => [1, 2, 3, 4, 5],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ];
    }

    // =========================================================================
    // Flag gate
    // =========================================================================

    public function test_flag_off_blocks_every_amenity_route_even_for_a_wildcard_admin(): void
    {
        $tenant = $this->makeTenant('Amenity Flag Off Cluster');
        $this->seedTenant($tenant);
        // Flag is left at its S1 default (false) — no enableFlag() call.
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity')
            ->assertStatus(403)
            ->assertJsonPath('code', 'AMENITY_RESERVATIONS_DISABLED');

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(403);
    }

    public function test_flag_on_unblocks_the_amenity_routes(): void
    {
        $tenant = $this->makeTenant('Amenity Flag On Cluster');
        $this->seedTenant($tenant);
        $this->enableFlag($tenant);
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity')
            ->assertOk();
    }

    // =========================================================================
    // Permission scoping (flag ON in all cases below — isolates the assertion
    // to the permission gate, not the flag gate)
    // =========================================================================

    public function test_resident_with_read_only_can_list_and_read_but_not_create_or_update(): void
    {
        $tenant = $this->makeTenant('Amenity Resident Cluster');
        $this->seedTenant($tenant);
        $this->enableFlag($tenant);

        $adminToken = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;
        $amenityId = $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(201)
            ->json('data.id');

        $residentToken = $this->makeUser($tenant)->createToken('resident', ['read.amenity'])->plainTextToken;

        $this->tenantRequest($residentToken, $tenant)
            ->getJson('/api/amenity')
            ->assertOk();

        $this->tenantRequest($residentToken, $tenant)
            ->getJson('/api/amenity/'.$amenityId)
            ->assertOk();

        $this->tenantRequest($residentToken, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(403);

        $this->tenantRequest($residentToken, $tenant)
            ->patchJson('/api/amenity/'.$amenityId, ['capacity' => 99])
            ->assertStatus(403);
    }
}
