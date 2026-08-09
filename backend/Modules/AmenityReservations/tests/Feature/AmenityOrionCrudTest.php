<?php

namespace Modules\AmenityReservations\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S4 — Amenity Orion CRUD: admin can create/update/list an amenity, attach
 * slots + blackouts (separate Orion resources scoped by `amenity_id`), and
 * upload/delete gallery photos. Also covers tenant isolation on the list
 * endpoint (Orion's index inherits TenantScope automatically).
 */
final class AmenityOrionCrudTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

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

    private function fakeImageFile(string $name = 'photo.jpg'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'amenity_crud_test_');
        $this->tempFiles[] = $tmpPath;

        // Minimal valid JPEG header bytes so Spatie's MIME sniffing accepts it.
        $jpegHeader = "\xFF\xD8\xFF\xE0".str_repeat('x', 1000);
        file_put_contents($tmpPath, $jpegHeader);

        return new UploadedFile($tmpPath, $name, 'image/jpeg', null, true);
    }

    private function amenityPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Salón de eventos '.uniqid(),
            'capacity' => 40,
            'available_weekdays' => [1, 2, 3, 4, 5],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ], $overrides);
    }

    public function test_admin_can_create_list_and_update_an_amenity(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $created = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity')
            ->assertOk()
            ->assertJsonFragment(['id' => $created['id']]);

        $this->tenantRequest($token, $tenant)
            ->patchJson('/api/amenity/'.$created['id'], ['capacity' => 55])
            ->assertOk()
            ->assertJsonPath('data.capacity', 55);
    }

    public function test_admin_can_attach_slots_and_blackouts_and_read_them_via_includes(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $amenityId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload(['reservation_mode_id' => ReservationMode::BY_SLOT]))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_slot', [
                'amenity_id' => $amenityId,
                'weekday' => 2,
                'start_time' => '09:00',
                'end_time' => '10:00',
            ])
            ->assertStatus(201);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_blackout', [
                'amenity_id' => $amenityId,
                'start_date' => now()->addDays(5)->toDateString(),
                'end_date' => now()->addDays(6)->toDateString(),
                'reason' => 'Mantenimiento',
            ])
            ->assertStatus(201);

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'?include=slots,blackouts')
            ->assertOk();

        $this->assertCount(1, $response->json('data.slots'));
        $this->assertCount(1, $response->json('data.blackouts'));
    }

    public function test_admin_can_upload_and_delete_a_gallery_photo(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $amenityId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity', $this->amenityPayload())
            ->assertStatus(201)
            ->json('data.id');

        $mediaId = $this->tenantRequest($token, $tenant)
            ->post('/api/amenity/'.$amenityId.'/media', ['file' => $this->fakeImageFile()])
            ->assertStatus(201)
            ->json('data.id');

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'?include=media')
            ->assertOk();

        $this->assertCount(1, $response->json('data.media'));

        $this->tenantRequest($token, $tenant)
            ->delete('/api/amenity/'.$amenityId.'/media/'.$mediaId)
            ->assertOk();

        $afterDelete = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'?include=media')
            ->assertOk();

        $this->assertCount(0, $afterDelete->json('data.media'));
    }

    public function test_amenity_list_is_isolated_per_tenant(): void
    {
        [$tenantA, $tokenA] = $this->adminTenant('Tenant A');
        [$tenantB, $tokenB] = $this->adminTenant('Tenant B');

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/amenity', $this->amenityPayload(['name' => 'Cancha de tenis '.uniqid()]))
            ->assertStatus(201);

        $listB = $this->tenantRequest($tokenB, $tenantB)
            ->getJson('/api/amenity')
            ->assertOk();

        $this->assertCount(0, $listB->json('data'), 'Tenant B must not see Tenant A amenities.');
    }

    // =========================================================================
    // Pagination cap — FIX (E2E QA — BUG 2/3): raised from Orion's default 100
    // =========================================================================

    /**
     * useAmenityFilterOptions (the amenity filter dropdown shared by the
     * calendar + reservation list filters panel) requests `limit: 200` — above
     * Orion's default 100 cap, which 422'd the request and left the dropdown
     * silently empty. AmenityController::maxLimit() now returns 200.
     */
    public function test_search_accepts_the_pagination_limit_the_amenity_filter_dropdown_requests(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity/search', ['limit' => 200])
            ->assertOk();
    }

    public function test_search_still_rejects_a_pagination_limit_above_the_raised_cap(): void
    {
        [$tenant, $token] = $this->adminTenant();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity/search', ['limit' => 201])
            ->assertStatus(422);
    }
}
