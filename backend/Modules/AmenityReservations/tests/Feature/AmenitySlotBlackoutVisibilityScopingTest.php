<?php

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityBlackout;
use Modules\AmenityReservations\Models\AmenitySlot;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Tenancy-review follow-up to AmenityVisibilityScopingTest: AmenitySlot and
 * AmenityBlackout are details of their owning amenity (routes gated only by
 * `read.amenity`, same as AmenityController), so they must inherit the SAME
 * target-based visibility boundary — otherwise a resident could read
 * `GET /amenity_slot?include=amenity` (or filter by the hidden amenity's id)
 * to see the schedule/blackout dates, and the hydrated parent, of an amenity
 * the AmenityController scope hides. Verifies
 * AmenitySlotController/AmenityBlackoutController::scopeToAccessibleAmenities().
 */
final class AmenitySlotBlackoutVisibilityScopingTest extends TestCase
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

    private function tenantWithFlagEnabled(string $name = 'Cluster'): Tenant
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        return $tenant;
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function residentToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', ['read.amenity'])->plainTextToken;

        return [$user, $token];
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function boardToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('board', ['read.amenity', 'update.amenity'])->plainTextToken;

        return [$user, $token];
    }

    private function unit(array $overrides = []): Unit
    {
        return Unit::factory()->create(array_merge([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'number' => (string) random_int(100000, 999999),
        ], $overrides));
    }

    private function attachResident(Unit $unit, User $user): void
    {
        UnitUser::create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
            'is_billing_owner' => true,
        ]);
    }

    private function amenity(array $overrides = []): Amenity
    {
        return Amenity::create(array_merge([
            'name' => 'Amenidad '.uniqid(),
            'capacity' => 10,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ], $overrides));
    }

    /**
     * @return array{filters: array<int, array{field: string, operator: string, value: string}>}
     */
    private function filterByAmenityIdBody(string $amenityId): array
    {
        return [
            'filters' => [
                ['field' => 'amenity_id', 'operator' => '=', 'value' => $amenityId],
            ],
        ];
    }

    /**
     * Builds a hidden amenity (target EXCLUDES the resident's unit) and an
     * in-target amenity (target INCLUDES it), each carrying one slot and one
     * blackout, and attaches the resident to the in-target tower's unit.
     *
     * @return array{
     *     hiddenAmenityId: string, hiddenSlotId: string, hiddenBlackoutId: string,
     *     inTargetAmenityId: string, inTargetSlotId: string, inTargetBlackoutId: string,
     * }
     */
    private function seedHiddenAndInTargetFixtures(Tenant $tenant, User $resident): array
    {
        return $this->inTenant($tenant, function () use ($resident): array {
            $inTargetTower = Tower::factory()->create();
            $hiddenTower = Tower::factory()->create();

            $residentUnit = $this->unit(['tower_id' => $inTargetTower->id]);
            $this->attachResident($residentUnit, $resident);

            $hiddenAmenity = $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$hiddenTower->id]],
            ]);
            $hiddenSlot = AmenitySlot::factory()->create(['amenity_id' => $hiddenAmenity->id]);
            $hiddenBlackout = AmenityBlackout::factory()->create(['amenity_id' => $hiddenAmenity->id]);

            $inTargetAmenity = $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$inTargetTower->id]],
            ]);
            $inTargetSlot = AmenitySlot::factory()->create(['amenity_id' => $inTargetAmenity->id]);
            $inTargetBlackout = AmenityBlackout::factory()->create(['amenity_id' => $inTargetAmenity->id]);

            return [
                'hiddenAmenityId' => $hiddenAmenity->id,
                'hiddenSlotId' => $hiddenSlot->id,
                'hiddenBlackoutId' => $hiddenBlackout->id,
                'inTargetAmenityId' => $inTargetAmenity->id,
                'inTargetSlotId' => $inTargetSlot->id,
                'inTargetBlackoutId' => $inTargetBlackout->id,
            ];
        });
    }

    // =========================================================================
    // Out-of-target resident — absent from the index (plain + include=amenity),
    // empty on an explicit filters[amenity_id]=<hidden> search, 404 on show
    // =========================================================================

    public function test_out_of_target_resident_does_not_see_slots_or_blackouts_of_a_hidden_amenity_in_the_index(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);
        $ids = $this->seedHiddenAndInTargetFixtures($tenant, $resident);

        $slotIndex = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_slot')
            ->assertOk();
        $slotIds = collect($slotIndex->json('data'))->pluck('id')->all();
        $this->assertNotContains($ids['hiddenSlotId'], $slotIds);
        $this->assertContains($ids['inTargetSlotId'], $slotIds);

        $blackoutIndex = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_blackout')
            ->assertOk();
        $blackoutIds = collect($blackoutIndex->json('data'))->pluck('id')->all();
        $this->assertNotContains($ids['hiddenBlackoutId'], $blackoutIds);
        $this->assertContains($ids['inTargetBlackoutId'], $blackoutIds);
    }

    /**
     * Orion only evaluates the `filters` param on the `/search` route (its
     * GET `index` action ignores it entirely — confirmed against
     * QueryBuilder::buildQuery(), which gates applyFiltersToQuery() behind
     * `$actionMethod === 'search'`). This is the one request in this file
     * that has to hit `/search`, so it doubles as proof the scope survives
     * being combined with an explicit filter on the hidden amenity's id,
     * not just an absence of one.
     */
    public function test_searching_by_a_hidden_amenitys_id_returns_no_slots_or_blackouts(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);
        $ids = $this->seedHiddenAndInTargetFixtures($tenant, $resident);

        $slotResponse = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_slot/search', $this->filterByAmenityIdBody($ids['hiddenAmenityId']))
            ->assertOk();
        $this->assertSame([], $slotResponse->json('data'));

        $blackoutResponse = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_blackout/search', $this->filterByAmenityIdBody($ids['hiddenAmenityId']))
            ->assertOk();
        $this->assertSame([], $blackoutResponse->json('data'));
    }

    public function test_including_amenity_never_hydrates_a_hidden_amenity(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);
        $ids = $this->seedHiddenAndInTargetFixtures($tenant, $resident);

        $slotIndex = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_slot?include=amenity')
            ->assertOk();
        $hydratedSlotAmenityIds = collect($slotIndex->json('data'))->pluck('amenity.id')->filter()->all();
        $this->assertNotContains($ids['hiddenAmenityId'], $hydratedSlotAmenityIds);
        $this->assertContains($ids['inTargetAmenityId'], $hydratedSlotAmenityIds);

        $blackoutIndex = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_blackout?include=amenity')
            ->assertOk();
        $hydratedBlackoutAmenityIds = collect($blackoutIndex->json('data'))->pluck('amenity.id')->filter()->all();
        $this->assertNotContains($ids['hiddenAmenityId'], $hydratedBlackoutAmenityIds);
        $this->assertContains($ids['inTargetAmenityId'], $hydratedBlackoutAmenityIds);
    }

    public function test_showing_a_hidden_amenitys_slot_or_blackout_by_id_is_not_found(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);
        $ids = $this->seedHiddenAndInTargetFixtures($tenant, $resident);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_slot/'.$ids['hiddenSlotId'])
            ->assertStatus(404);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_blackout/'.$ids['hiddenBlackoutId'])
            ->assertStatus(404);
    }

    // =========================================================================
    // In-target resident — sees that amenity's slot + blackout
    // =========================================================================

    public function test_in_target_resident_sees_the_amenitys_slots_and_blackouts(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);
        $ids = $this->seedHiddenAndInTargetFixtures($tenant, $resident);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_slot/'.$ids['inTargetSlotId'])
            ->assertOk()
            ->assertJsonPath('data.id', $ids['inTargetSlotId']);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_blackout/'.$ids['inTargetBlackoutId'])
            ->assertOk()
            ->assertJsonPath('data.id', $ids['inTargetBlackoutId']);
    }

    // =========================================================================
    // update.amenity holder — bypass, sees every slot/blackout regardless of target
    // =========================================================================

    public function test_manager_with_update_amenity_bypasses_the_target_and_sees_every_slot_and_blackout(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident] = $this->residentToken($tenant);
        [, $managerToken] = $this->boardToken($tenant);
        $ids = $this->seedHiddenAndInTargetFixtures($tenant, $resident);

        $slotIndex = $this->tenantRequest($managerToken, $tenant)
            ->getJson('/api/amenity_slot')
            ->assertOk();
        $slotIds = collect($slotIndex->json('data'))->pluck('id')->all();
        $this->assertContains($ids['hiddenSlotId'], $slotIds);
        $this->assertContains($ids['inTargetSlotId'], $slotIds);

        $this->tenantRequest($managerToken, $tenant)
            ->getJson('/api/amenity_slot/'.$ids['hiddenSlotId'])
            ->assertOk();

        $blackoutIndex = $this->tenantRequest($managerToken, $tenant)
            ->getJson('/api/amenity_blackout')
            ->assertOk();
        $blackoutIds = collect($blackoutIndex->json('data'))->pluck('id')->all();
        $this->assertContains($ids['hiddenBlackoutId'], $blackoutIds);
        $this->assertContains($ids['inTargetBlackoutId'], $blackoutIds);

        $this->tenantRequest($managerToken, $tenant)
            ->getJson('/api/amenity_blackout/'.$ids['hiddenBlackoutId'])
            ->assertOk();
    }
}
