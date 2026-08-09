<?php

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\AmenitySlot;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
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
 * S6 — reservation CREATION only (ReservationService::create). FSM
 * transitions (S7) and earmark payment wiring (S8) are out of scope.
 *
 * Follows the project's tenant-test convention (mirrors ChargeBatchSummaryTest):
 * model setup happens INSIDE $tenant->run()/inTenant(), HTTP requests happen
 * OUTSIDE via tenantRequest() — tenantRequest() ends any active tenancy
 * before issuing the request, so it must never be nested inside a run() block.
 */
final class AmenityReservationCreationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

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
     * A resident-shaped token: exactly the abilities RolePermissionTableSeeder
     * grants the resident role for this domain (no update.amenity_reservation).
     *
     * @return array{0: User, 1: string}
     */
    private function residentToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
    }

    /**
     * A board/admin-shaped token: holds update.amenity_reservation (the
     * $amenities writeNoDelete grant), so ReservationService's membership
     * check treats it as cluster-wide, not own-unit-only.
     *
     * @return array{0: User, 1: string}
     */
    private function boardToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('board', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation', 'update.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
    }

    /**
     * Pre-existing flakiness fix: UnitFactory's `number` is
     * `optional()->numerify('###')` (~50% null), so two units in the SAME
     * tenant (same unit_type_id, no tower/floor) collide on
     * `unit_identity_unique` (all-NULL identity tuple) ~1 in 4 times. Force a
     * unique `number` so tests creating multiple units per tenant are
     * deterministic instead of ~25% flaky.
     */
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

    /** A future Wednesday — deterministic, avoids weekday flakiness. */
    private function futureDate(): Carbon
    {
        return Carbon::now()->addWeek()->next(Carbon::WEDNESDAY);
    }

    /** A future Sunday — guaranteed outside a Monday..Friday weekday set. */
    private function futureClosedWeekday(): Carbon
    {
        return Carbon::now()->addWeek()->next(Carbon::SUNDAY);
    }

    // =========================================================================
    // Happy paths — initial FSM status (spec R1)
    // =========================================================================

    public function test_free_no_approval_amenity_creates_a_confirmed_reservation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.reservation_status_id', ReservationStatus::CONFIRMED);
        $response->assertJsonPath('data.amount_outstanding.amount', '0');
        $this->assertNull($response->json('data.cost_amount'));
    }

    public function test_amenity_requiring_approval_creates_a_pending_approval_reservation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity(['requires_approval' => true]);
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.reservation_status_id', ReservationStatus::PENDING_APPROVAL);
        $this->assertNull($response->json('data.payment_deadline'));
    }

    public function test_amenity_with_cost_creates_a_pending_payment_reservation_with_deadline_and_outstanding(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        // has_cost=true, requires_deposit=false, deposit_due_days=null —
        // exercises the DEFAULT_PAYMENT_DEADLINE_DAYS fallback.
        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity(['has_cost' => true, 'cost_amount' => 100000]);
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $before = now();

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);

        $response->assertJsonPath('data.reservation_status_id', ReservationStatus::PENDING_PAYMENT);
        $response->assertJsonPath('data.cost_amount.amount', '100000');
        $response->assertJsonPath('data.amount_outstanding.amount', '100000');

        $deadline = Carbon::parse($response->json('data.payment_deadline'));
        $this->assertTrue($deadline->greaterThan($before), 'payment_deadline must be in the future.');
        $this->assertTrue($deadline->lessThanOrEqualTo($before->copy()->addDays(4)), 'payment_deadline must use the sensible default (3 days) when deposit_due_days is null.');
    }

    // =========================================================================
    // Unit-membership authorization
    // =========================================================================

    public function test_resident_cannot_reserve_for_a_unit_they_do_not_belong_to(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->residentToken($tenant);

        [$amenityId, $foreignUnitId] = $this->inTenant($tenant, function (): array {
            $amenity = $this->amenity();
            $foreignUnit = $this->unit(); // NOT attached to the resident

            return [$amenity->id, $foreignUnit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $foreignUnitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(403);
    }

    public function test_board_member_can_reserve_for_any_unit(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);

        [$amenityId, $anyUnitId] = $this->inTenant($tenant, function (): array {
            $amenity = $this->amenity();
            $anyUnit = $this->unit(); // NOT attached to the board member

            return [$amenity->id, $anyUnit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $anyUnitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // Amenity target enforcement (Slice B — no bypass, applies to everyone)
    // =========================================================================

    public function test_create_for_out_of_target_unit_by_resident_returns_422(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $inTargetTower = Tower::factory()->create();
            $residentTower = Tower::factory()->create();
            $unit = $this->unit(['tower_id' => $residentTower->id]);
            $this->attachResident($unit, $resident);

            $amenity = $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$inTargetTower->id]],
            ]);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(422);

        $this->inTenant($tenant, function () use ($amenityId): void {
            $this->assertSame(0, AmenityReservation::where('amenity_id', $amenityId)->count());
        });
    }

    public function test_create_for_out_of_target_unit_by_admin_also_returns_422(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function (): array {
            $inTargetTower = Tower::factory()->create();
            $foreignTower = Tower::factory()->create();
            $unit = $this->unit(['tower_id' => $foreignTower->id]);

            $amenity = $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$inTargetTower->id]],
            ]);

            return [$amenity->id, $unit->id];
        });

        // A board/admin token holds update.amenity_reservation, which bypasses
        // authorizeUnitMembership()'s own-unit restriction — but must NOT
        // bypass the amenity target check (design's Architecture Decision #3:
        // no bypass on create, applies to everyone).
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(422);

        $this->inTenant($tenant, function () use ($amenityId): void {
            $this->assertSame(0, AmenityReservation::where('amenity_id', $amenityId)->count());
        });
    }

    public function test_create_for_in_target_unit_succeeds(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $tower = Tower::factory()->create();
            $unit = $this->unit(['tower_id' => $tower->id]);
            $this->attachResident($unit, $resident);

            $amenity = $this->amenity([
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$tower->id]],
            ]);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);
    }

    public function test_create_for_all_target_succeeds_unaffected(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            // No target_type override — the column defaults to 'all'.
            return [$this->amenity()->id, $unit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // Rules acceptance
    // =========================================================================

    public function test_reservation_is_rejected_when_rules_text_present_and_not_accepted(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity(['rules_text' => 'No se permiten mascotas.']);
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_reservation_succeeds_when_rules_accepted(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity(['rules_text' => 'No se permiten mascotas.']);
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
                'rules_accepted' => true,
            ])
            ->assertStatus(201);

        $this->assertNotNull($response->json('data.rules_accepted_at'));
    }

    // =========================================================================
    // Availability rejections (reused from AmenityAvailabilityService)
    // =========================================================================

    public function test_out_of_service_amenity_rejects_creation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity(['out_of_service' => true, 'out_of_service_reason' => 'Remodelación']);
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_closed_weekday_rejects_creation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity(['available_weekdays' => [1, 2, 3, 4, 5]]); // Monday..Friday
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureClosedWeekday()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_blackout_date_rejects_creation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);
        $date = $this->futureDate();

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident, $date): array {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $amenity->blackouts()->create([
                'start_date' => $date->toDateString(),
                'end_date' => $date->toDateString(),
                'reason' => 'Mantenimiento',
            ]);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $date->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_past_date_rejects_creation(): void
    {
        // Freeze at midday UTC so "yesterday" is unambiguously a past date in the
        // tenant timezone (UTC-6). Bare now()->subDay() flakes in the 00:00-06:00
        // UTC window, where UTC-yesterday still equals the tenant's local today —
        // which the service (correctly, tenant-tz-aware) does NOT reject.
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => now()->subDay()->toDateString(),
            ])
            ->assertStatus(422);
    }

    // =========================================================================
    // Double booking / capacity (spec R2)
    // =========================================================================

    public function test_by_day_second_reservation_for_the_same_amenity_and_date_is_rejected_with_409(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);
        $date = $this->futureDate()->toDateString();

        [$amenityId, $unitAId, $unitBId] = $this->inTenant($tenant, function (): array {
            $amenity = $this->amenity();

            return [$amenity->id, $this->unit()->id, $this->unit()->id];
        });

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitAId,
                'reservation_date' => $date,
            ])
            ->assertStatus(201);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitBId,
                'reservation_date' => $date,
            ])
            ->assertStatus(409);
    }

    public function test_by_slot_capacity_is_enforced_the_capacity_plus_one_reservation_is_rejected(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $token] = $this->boardToken($tenant);
        $date = $this->futureDate();

        [$amenityId, $slotId, $unitIds] = $this->inTenant($tenant, function () use ($date): array {
            $amenity = $this->amenity(['reservation_mode_id' => ReservationMode::BY_SLOT, 'capacity' => 10]);

            $slot = AmenitySlot::create([
                'amenity_id' => $amenity->id,
                'weekday' => (int) $date->dayOfWeek,
                'start_time' => '09:00:00',
                'end_time' => '10:00:00',
                'capacity_override' => 2,
            ]);

            return [$amenity->id, $slot->id, [$this->unit()->id, $this->unit()->id, $this->unit()->id]];
        });

        // First two reservations (capacity=2) must succeed.
        foreach (array_slice($unitIds, 0, 2) as $unitId) {
            $this->tenantRequest($token, $tenant)
                ->postJson('/api/amenity_reservation', [
                    'amenity_id' => $amenityId,
                    'unit_id' => $unitId,
                    'reservation_date' => $date->toDateString(),
                    'slot_id' => $slotId,
                ])
                ->assertStatus(201);
        }

        // The 3rd (capacity + 1) must be rejected.
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitIds[2],
                'reservation_date' => $date->toDateString(),
                'slot_id' => $slotId,
            ])
            ->assertStatus(409);
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_reservation_creation_is_isolated_per_tenant(): void
    {
        $tenantA = $this->tenantWithFlagEnabled('Tenant A');
        $tenantB = $this->tenantWithFlagEnabled('Tenant B');

        $amenityAId = $this->inTenant($tenantA, fn () => $this->amenity()->id);

        [$residentB, $tokenB] = $this->residentToken($tenantB);
        $unitBId = $this->inTenant($tenantB, function () use ($residentB): string {
            $unit = $this->unit();
            $this->attachResident($unit, $residentB);

            return $unit->id;
        });

        // Tenant B's user cannot reserve Tenant A's amenity: the amenity_id
        // exists-rule (scoped to tenant('id')) rejects it before the service
        // even resolves the model.
        $this->tenantRequest($tokenB, $tenantB)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityAId,
                'unit_id' => $unitBId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(422);
    }
}
