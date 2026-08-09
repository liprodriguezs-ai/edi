<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * GET /my-restrictions (design §6.2, R-DELQ-1/6/9). Per-unit payload — a
 * resident with one good-standing and one delinquent unit must see BOTH,
 * each correctly marked (never an ANY-aggregate).
 */
final class DelinquencyRestrictionControllerTest extends TestCase
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

    private function unit(): Unit
    {
        return Unit::create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => (string) mt_rand(1, 99999),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'ownership_share' => 1.0,
        ]);
    }

    private function linkToUnit(Unit $unit, User $user): void
    {
        UnitUser::create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
        ]);
    }

    private function makeUnitOverdue(Unit $unit, int $balance): void
    {
        $unit->charge_status_id = ChargeStatus::OVERDUE;
        $unit->save();

        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
        $concept = ChargeConcept::create([
            'code' => 'MYDLQ_'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'name' => 'My-restrictions test concept',
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
    }

    private function enableRestrictions(int $minOverdueAmount = 0): void
    {
        Setting::updateOrCreate(['slug' => DelinquencySettings::RESTRICTIONS_ENABLED], ['name' => DelinquencySettings::RESTRICTIONS_ENABLED, 'slug' => DelinquencySettings::RESTRICTIONS_ENABLED, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT], ['name' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'value' => $minOverdueAmount, 'type' => Setting::TYPE_INTEGER]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::BLOCK_AMENITIES], ['name' => DelinquencySettings::BLOCK_AMENITIES, 'slug' => DelinquencySettings::BLOCK_AMENITIES, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::BLOCK_VISITOR_ACCESS], ['name' => DelinquencySettings::BLOCK_VISITOR_ACCESS, 'slug' => DelinquencySettings::BLOCK_VISITOR_ACCESS, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
    }

    // =========================================================================
    // Per-unit precision (R-DELQ-1)
    // =========================================================================

    public function test_resident_with_one_current_and_one_overdue_unit_sees_both_marked_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);

        [$currentUnitId, $overdueUnitId] = $this->inTenant($tenant, function () use ($resident): array {
            $currentUnit = $this->unit();
            $overdueUnit = $this->unit();
            $this->linkToUnit($currentUnit, $resident);
            $this->linkToUnit($overdueUnit, $resident);

            $this->makeUnitOverdue($overdueUnit, 750_000);
            $this->enableRestrictions(500_000);

            return [$currentUnit->id, $overdueUnit->id];
        });

        $token = $resident->createToken('resident', [])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/my-restrictions')
            ->assertOk();

        $response->assertJsonPath('restricted', true);
        $this->assertNotEmpty($response->json('notice'));

        $units = collect($response->json('units'))->keyBy('unit_id');

        $this->assertFalse($units[$currentUnitId]['restricted']);
        $this->assertArrayNotHasKey('overdue_balance', $units[$currentUnitId]);

        $this->assertTrue($units[$overdueUnitId]['restricted']);
        $this->assertTrue($units[$overdueUnitId]['features']['amenities']);
        $this->assertTrue($units[$overdueUnitId]['features']['visitor_access']);
        $this->assertSame('750000', $units[$overdueUnitId]['overdue_balance']['amount']);
    }

    // =========================================================================
    // Own-units-only scoping
    // =========================================================================

    public function test_resident_never_sees_another_residents_units(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);
        $otherResident = $this->makeUser($tenant);

        $this->inTenant($tenant, function () use ($resident, $otherResident): void {
            $ownUnit = $this->unit();
            $this->linkToUnit($ownUnit, $resident);

            $foreignUnit = $this->unit();
            $this->linkToUnit($foreignUnit, $otherResident);
            $this->makeUnitOverdue($foreignUnit, 900_000);
            $this->enableRestrictions(0);
        });

        $token = $resident->createToken('resident', [])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/my-restrictions')
            ->assertOk();

        $this->assertCount(1, $response->json('units'));
        $this->assertFalse($response->json('restricted'));
    }

    // =========================================================================
    // Master switch off — R-DELQ-1
    // =========================================================================

    public function test_master_switch_off_reports_no_unit_as_restricted(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);

        $this->inTenant($tenant, function () use ($resident): void {
            $unit = $this->unit();
            $this->linkToUnit($unit, $resident);
            $this->makeUnitOverdue($unit, 900_000);
            // restrictions_enabled stays at its seeded default (false).
        });

        $token = $resident->createToken('resident', [])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/my-restrictions')
            ->assertOk()
            ->assertJsonPath('restricted', false);
    }
}
