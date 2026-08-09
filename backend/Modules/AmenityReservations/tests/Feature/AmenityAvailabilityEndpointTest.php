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
 * S5 — GET amenity/{id}/availability: gated by auth + tenancy + the
 * `amenityReservationsEnabled` flag (like every other amenity route) and by
 * `read.amenity` (residents already hold this ability since S2). Registered
 * before Orion::resource('amenity') so it is matched ahead of the show route.
 */
final class AmenityAvailabilityEndpointTest extends TestCase
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

    /**
     * @return array{0: Tenant, 1: string}
     */
    private function adminTenant(string $name = 'Cluster'): array
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;

        return [$tenant, $token];
    }

    private function amenityPayload(): array
    {
        return [
            'name' => 'Salón de eventos '.uniqid(),
            'capacity' => 40,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ];
    }

    public function test_admin_can_fetch_availability_for_a_date(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $amenityId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(201)
            ->json('data.id');

        $date = now()->addDay()->toDateString();

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'/availability?date='.$date)
            ->assertOk();

        $response->assertJsonPath('data.mode', 'by_day');
        $response->assertJsonPath('data.days.0.date', $date);
        $this->assertArrayHasKey('available', $response->json('data.days.0'));
    }

    public function test_resident_with_read_only_amenity_permission_can_fetch_availability(): void
    {
        [$tenant, $adminToken] = $this->adminTenant();

        $amenityId = $this->tenantRequest($adminToken, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(201)
            ->json('data.id');

        $residentToken = $this->makeUser($tenant)->createToken('resident', ['read.amenity'])->plainTextToken;

        $this->tenantRequest($residentToken, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'/availability?date='.now()->addDay()->toDateString())
            ->assertOk();
    }

    public function test_flag_off_blocks_the_availability_endpoint(): void
    {
        $tenant = $this->makeTenant('Amenity Availability Flag Off');
        $this->seedTenant($tenant);
        $token = $this->makeUser($tenant)->createToken('admin', ['*'])->plainTextToken;

        // Flag defaults to false — no amenity can even be created, so exercise
        // the gate directly against a random id (still 403 before any 404).
        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/00000000-0000-0000-0000-000000000000/availability?date='.now()->addDay()->toDateString())
            ->assertStatus(403)
            ->assertJsonPath('code', 'AMENITY_RESERVATIONS_DISABLED');
    }

    public function test_past_date_returns_a_422(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $amenityId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(201)
            ->json('data.id');

        // subDays(2), not subDay(): 2 days back in UTC is unambiguously a past date
        // in the tenant timezone (offset < 24h), avoiding the 00:00-06:00 UTC
        // boundary window where UTC-yesterday still equals the tenant's local today.
        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'/availability?date='.now()->subDays(2)->toDateString())
            ->assertStatus(422);
    }

    public function test_availability_is_isolated_per_tenant(): void
    {
        [$tenantA, $tokenA] = $this->adminTenant('Tenant A');
        [$tenantB, $tokenB] = $this->adminTenant('Tenant B');

        $amenityIdA = $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($tokenB, $tenantB)
            ->getJson('/api/amenity/'.$amenityIdA.'/availability?date='.now()->addDay()->toDateString())
            ->assertStatus(404);
    }
}
