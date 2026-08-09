<?php

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Support\DelinquencySettings;
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
 * Delinquency hook 1 (design §5): ReservationService::create() asserts
 * NOT restricted for FEATURE_AMENITIES right after authorizeUnitMembership.
 * Covers R-DELQ-4 (blocked), R-DELQ-5 (browsing never blocked), R-DELQ-10
 * (tenant isolation), and the accepted board-bypass pin (design §10).
 */
final class AmenityReservationDelinquencyGuardTest extends TestCase
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

    // =========================================================================
    // Helpers
    // =========================================================================

    private function tenantWithFlagEnabled(string $name = 'Cluster'): Tenant
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        return $tenant;
    }

    /** @return array{0: User, 1: string} */
    private function residentToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
    }

    /** @return array{0: User, 1: string} */
    private function boardToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('board', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation', 'update.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
    }

    private function unit(): Unit
    {
        return Unit::factory()->create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'number' => (string) random_int(100000, 999999),
        ]);
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

    private function amenity(): Amenity
    {
        return Amenity::create([
            'name' => 'Amenidad '.uniqid(),
            'capacity' => 10,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ]);
    }

    private function futureDate(): string
    {
        return now()->addWeek()->toDateString();
    }

    /** Makes $unit OVERDUE with a charge owing $balance minor units. */
    private function makeOverdueUnit(int $balance): Unit
    {
        $unit = $this->unit();
        $unit->charge_status_id = ChargeStatus::OVERDUE;
        $unit->save();

        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
        $concept = ChargeConcept::create([
            'code' => 'AMDLQ_'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'name' => 'Delinquency guard test concept',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        Charge::create([
            'charge_concept_id' => $concept->id,
            'unit_id' => $unit->id,
            'expected_amount' => $balance,
            'paid_amount' => 0,
            'issued_at' => '2026-01-01',
            'due_date' => '2026-01-10',
            'charge_status_id' => ChargeStatus::OVERDUE,
            'is_cancelled' => false,
        ]);

        return $unit->fresh();
    }

    private function enableAmenityRestrictions(int $minOverdueAmount = 0): void
    {
        Setting::updateOrCreate(['slug' => DelinquencySettings::RESTRICTIONS_ENABLED], ['name' => DelinquencySettings::RESTRICTIONS_ENABLED, 'slug' => DelinquencySettings::RESTRICTIONS_ENABLED, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT], ['name' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'value' => $minOverdueAmount, 'type' => Setting::TYPE_INTEGER]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::BLOCK_AMENITIES], ['name' => DelinquencySettings::BLOCK_AMENITIES, 'slug' => DelinquencySettings::BLOCK_AMENITIES, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
    }

    // =========================================================================
    // R-DELQ-4 — blocked
    // =========================================================================

    public function test_delinquent_unit_is_blocked_from_creating_a_reservation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity();
            $unit = $this->makeOverdueUnit(750_000);
            $this->attachResident($unit, $resident);
            $this->enableAmenityRestrictions(500_000);

            return [$amenity->id, $unit->id];
        });

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate(),
            ])
            ->assertStatus(403);

        $response->assertJsonPath('code', 'DELINQUENT_RESTRICTED');
        $response->assertJsonPath('overdue_balance.amount', '750000');
        $this->assertNotEmpty($response->json('notice_text'));
    }

    // =========================================================================
    // R-DELQ-5 — browsing never blocked
    // =========================================================================

    public function test_amenity_browsing_is_unaffected_for_a_delinquent_unit(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $token] = $this->residentToken($tenant);

        $amenityId = $this->inTenant($tenant, function () use ($resident): string {
            $amenity = $this->amenity();
            $unit = $this->makeOverdueUnit(750_000);
            $this->attachResident($unit, $resident);
            $this->enableAmenityRestrictions(500_000);

            return $amenity->id;
        });

        $this->tenantRequest($token, $tenant)->getJson('/api/amenity')->assertOk();
        $this->tenantRequest($token, $tenant)->getJson("/api/amenity/{$amenityId}")->assertOk();
        $this->tenantRequest($token, $tenant)
            ->getJson("/api/amenity/{$amenityId}/availability?date=".$this->futureDate())
            ->assertOk();
    }

    // =========================================================================
    // Accepted board-bypass pin (design §10 — NOT a leak, do not "fix")
    // =========================================================================

    public function test_board_member_reserving_for_a_restricted_foreign_unit_gets_resident_403_with_balance(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$board, $token] = $this->boardToken($tenant);

        [$amenityId, $foreignUnitId] = $this->inTenant($tenant, function (): array {
            $amenity = $this->amenity();
            $foreignUnit = $this->makeOverdueUnit(900_000);
            $this->enableAmenityRestrictions(500_000);

            return [$amenity->id, $foreignUnit->id];
        });

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $foreignUnitId,
                'reservation_date' => $this->futureDate(),
            ])
            ->assertStatus(403);

        $response->assertJsonPath('code', 'DELINQUENT_RESTRICTED');
        $response->assertJsonPath('overdue_balance.amount', '900000');
    }

    // =========================================================================
    // R-DELQ-10 — tenant isolation
    // =========================================================================

    public function test_tenant_a_restrictions_never_affect_a_delinquent_unit_in_tenant_b(): void
    {
        $tenantA = $this->tenantWithFlagEnabled('Cluster A');
        $this->inTenant($tenantA, function (): void {
            $this->enableAmenityRestrictions(0);
        });

        $tenantB = $this->tenantWithFlagEnabled('Cluster B');
        [$residentB, $tokenB] = $this->residentToken($tenantB);

        // tenantB leaves delinquency.restrictions_enabled at its seeded default (false).
        [$amenityId, $unitId] = $this->inTenant($tenantB, function () use ($residentB): array {
            $amenity = $this->amenity();
            $unit = $this->makeOverdueUnit(1_000_000);
            $this->attachResident($unit, $residentB);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($tokenB, $tenantB)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate(),
            ])
            ->assertStatus(201);
    }
}
