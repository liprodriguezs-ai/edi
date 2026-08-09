<?php

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
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
 * A2 — resident read-scoping for amenity visibility (design.md §File Changes
 * / AmenityController). Mirrors ChatChannelController's
 * scopeToAccessibleChannels bypass semantics (null user | tokenCan('*') |
 * tokenCan('update.amenity')) applied to Orion's index/show fetch queries,
 * plus the custom availability() route, which resolves the Amenity directly
 * via findOrFail() and therefore needs its own explicit check.
 */
final class AmenityVisibilityScopingTest extends TestCase
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

    /**
     * A resident token that ALSO reads amenity_reservation — needed only by
     * the "target narrowing is not retroactive" regression test below, which
     * exercises `AmenityReservationController`'s own (untouched)
     * ScopesToResidentUnits scoping, not this slice's AmenityController scoping.
     *
     * @return array{0: User, 1: string}
     */
    private function residentReservationToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', ['read.amenity', 'read.amenity_reservation'])->plainTextToken;

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

    // =========================================================================
    // In-target resident — visible in index, show, and availability
    // =========================================================================

    public function test_resident_in_target_sees_the_amenity_in_index_show_and_availability(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        $amenityId = $this->inTenant($tenant, function () use ($resident): string {
            $tower = Tower::factory()->create();
            $unit = $this->unit(['tower_id' => $tower->id]);
            $this->attachResident($unit, $resident);

            return $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$tower->id]],
            ])->id;
        });

        $index = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity')
            ->assertOk();
        $this->assertContains($amenityId, collect($index->json('data'))->pluck('id')->all());

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId)
            ->assertOk()
            ->assertJsonPath('data.id', $amenityId);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'/availability?date='.now()->addDay()->toDateString())
            ->assertOk();
    }

    // =========================================================================
    // Out-of-target resident — absent from index, 404 on show/availability
    // =========================================================================

    public function test_resident_out_of_target_cannot_see_the_amenity_anywhere(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        $amenityId = $this->inTenant($tenant, function () use ($resident): string {
            $inTargetTower = Tower::factory()->create();
            $residentTower = Tower::factory()->create();
            $residentUnit = $this->unit(['tower_id' => $residentTower->id]);
            $this->attachResident($residentUnit, $resident);

            return $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$inTargetTower->id]],
            ])->id;
        });

        $index = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity')
            ->assertOk();
        $this->assertNotContains($amenityId, collect($index->json('data'))->pluck('id')->all());

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId)
            ->assertStatus(404);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'/availability?date='.now()->addDay()->toDateString())
            ->assertStatus(404);
    }

    // =========================================================================
    // Default target_type='all' — zero behavior change
    // =========================================================================

    public function test_default_all_target_is_visible_to_every_resident(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        $amenityId = $this->inTenant($tenant, function () use ($resident): string {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            // No target_type override — the column defaults to 'all'.
            return $this->amenity()->id;
        });

        $index = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity')
            ->assertOk();
        $this->assertContains($amenityId, collect($index->json('data'))->pluck('id')->all());

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId)
            ->assertOk();
    }

    // =========================================================================
    // update.amenity holder — bypass, sees everything regardless of target
    // =========================================================================

    public function test_update_amenity_holder_bypasses_the_target_and_sees_every_amenity(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);

        $amenityId = $this->inTenant($tenant, function (): string {
            $foreignTower = Tower::factory()->create();

            return $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$foreignTower->id]],
            ])->id;
        });

        $index = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity')
            ->assertOk();
        $this->assertContains($amenityId, collect($index->json('data'))->pluck('id')->all());

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId)
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId.'/availability?date='.now()->addDay()->toDateString())
            ->assertOk();
    }

    // =========================================================================
    // Multi-unit resident — visible when only ONE unit is in-target
    // =========================================================================

    public function test_multi_unit_resident_sees_the_amenity_when_only_one_unit_is_in_target(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        $amenityId = $this->inTenant($tenant, function () use ($resident): string {
            $outOfTargetTower = Tower::factory()->create();
            $inTargetTower = Tower::factory()->create();

            $outOfTargetUnit = $this->unit(['tower_id' => $outOfTargetTower->id]);
            $inTargetUnit = $this->unit(['tower_id' => $inTargetTower->id]);
            $this->attachResident($outOfTargetUnit, $resident);
            $this->attachResident($inTargetUnit, $resident);

            return $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$inTargetTower->id]],
            ])->id;
        });

        $index = $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity')
            ->assertOk();
        $this->assertContains($amenityId, collect($index->json('data'))->pluck('id')->all());

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId)
            ->assertOk();
    }

    // =========================================================================
    // Target narrowing is not retroactive — a regression guard for
    // AmenityReservationController's own (untouched) read scoping
    // =========================================================================

    public function test_narrowing_an_amenitys_target_does_not_retroactively_hide_an_existing_reservation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentReservationToken($tenant);

        [$reservationId, $amenityId] = $this->inTenant($tenant, function () use ($resident): array {
            $foreignTower = Tower::factory()->create();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            // Amenity starts wide open ('all') when the reservation is made.
            $amenity = $this->amenity();
            $reservation = AmenityReservation::factory()->confirmed()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'user_id' => $resident->id,
            ]);

            // Now narrow the target to explicitly EXCLUDE the resident's unit.
            $amenity->update([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$foreignTower->id]],
            ]);

            return [$reservation->id, $amenity->id];
        });

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity_reservation/'.$reservationId)
            ->assertOk()
            ->assertJsonPath('data.id', $reservationId);

        // The amenity itself IS now hidden from this resident (this slice's
        // scoping working as intended) — the reservation record is not.
        $this->tenantRequest($token, $tenant)
            ->getJson('/api/amenity/'.$amenityId)
            ->assertStatus(404);
    }
}
