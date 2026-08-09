<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for ChargeController filter extensions (plan §8.2 charges-filters).
 *
 * Covers:
 *  - expected_amount filter (between operator)
 *  - paid_amount filter (between operator)
 *  - due_date filter (between operator)
 *  - issued_at filter (between operator)
 *  - owner_ids custom parameter (billing owner via unit_user.is_billing_owner)
 *
 * Data setup uses $tenant->run() so BelongsToTenant stamps tenant_id correctly.
 * HTTP calls use tenantRequest() so the API sees the same tenant scope.
 */
final class ChargeFilterTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private Tenant $tenant;

    private string $token;

    private string $unitTypeId;

    private string $unitStatusId;

    private string $conceptId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant('Charge Filter Test Cluster');
        $this->seedTenant($this->tenant);

        // Resolve seeded catalog IDs and create a shared concept inside the tenant context
        $this->tenant->run(function (): void {
            $this->unitTypeId = Catalog::id(UnitType::class, 'Casa', $this->tenant);
            $this->unitStatusId = Catalog::id(UnitStatus::class, 'Activa', $this->tenant);

            $categoryId = Catalog::id(ChargeConceptCategory::class, 'Ordinaria', $this->tenant);
            $concept = ChargeConcept::create([
                'code' => 'TEST-FILTER',
                'name' => 'Test Filter Concept',
                'charge_concept_category_id' => $categoryId,
                'is_active' => true,
            ]);
            $this->conceptId = $concept->id;
        });

        $user = $this->makeUser($this->tenant);
        $this->token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($this->token, $this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createUnit(): Unit
    {
        $unit = null;
        $this->tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'number' => (string) mt_rand(100, 99999),
                'unit_type_id' => $this->unitTypeId,
                'unit_status_id' => $this->unitStatusId,
            ]);
        });

        /** @var Unit $unit */
        return $unit;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCharge(Unit $unit, array $overrides = []): Charge
    {
        $charge = null;
        $this->tenant->run(function () use ($unit, $overrides, &$charge): void {
            $charge = Charge::create(array_merge([
                'unit_id' => $unit->id,
                'charge_concept_id' => $this->conceptId,
                'expected_amount' => 10000,
                'paid_amount' => 0,
                'issued_at' => now()->toDateString(),
                'due_date' => now()->addMonth()->toDateString(),
                'grace_days' => 0,
                'charge_status_id' => ChargeStatus::PENDING,
                'is_cancelled' => false,
            ], $overrides));
        });

        /** @var Charge $charge */
        return $charge;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function searchCharges(array $body = []): TestResponse
    {
        return $this->postJson('/api/charge/search', $body);
    }

    // ─── Tests ───────────────────────────────────────────────────────────────

    public function test_filters_charges_by_expected_amount_between(): void
    {
        $unit = $this->createUnit();

        $low = $this->createCharge($unit, ['expected_amount' => 5000]);   // $50
        $mid = $this->createCharge($unit, ['expected_amount' => 15000]);  // $150
        $high = $this->createCharge($unit, ['expected_amount' => 50000]); // $500

        $response = $this->searchCharges([
            'filters' => [
                ['field' => 'expected_amount', 'operator' => '>=', 'value' => 10000],
                ['field' => 'expected_amount', 'operator' => '<=', 'value' => 20000],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($mid->id, $ids);
        $this->assertNotContains($low->id, $ids);
        $this->assertNotContains($high->id, $ids);
    }

    public function test_filters_charges_by_paid_amount_between(): void
    {
        $unit = $this->createUnit();

        $unpaid = $this->createCharge($unit, ['paid_amount' => 0]);
        $partial = $this->createCharge($unit, ['paid_amount' => 8000]);
        $full = $this->createCharge($unit, ['expected_amount' => 20000, 'paid_amount' => 20000]);

        $response = $this->searchCharges([
            'filters' => [
                ['field' => 'paid_amount', 'operator' => '>=', 'value' => 1],
                ['field' => 'paid_amount', 'operator' => '<=', 'value' => 9999],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($partial->id, $ids);
        $this->assertNotContains($unpaid->id, $ids);
        $this->assertNotContains($full->id, $ids);
    }

    public function test_filters_charges_by_due_date_between(): void
    {
        $unit = $this->createUnit();

        $past = $this->createCharge($unit, ['due_date' => '2024-01-15']);
        $current = $this->createCharge($unit, ['due_date' => '2025-06-01']);
        $future = $this->createCharge($unit, ['due_date' => '2026-12-31']);

        $response = $this->searchCharges([
            'filters' => [
                ['field' => 'due_date', 'operator' => '>=', 'value' => '2025-01-01'],
                ['field' => 'due_date', 'operator' => '<=', 'value' => '2025-12-31'],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($current->id, $ids);
        $this->assertNotContains($past->id, $ids);
        $this->assertNotContains($future->id, $ids);
    }

    public function test_filters_charges_by_issued_at_between(): void
    {
        $unit = $this->createUnit();

        $old = $this->createCharge($unit, ['issued_at' => '2023-06-01']);
        $recent = $this->createCharge($unit, ['issued_at' => '2025-03-01']);

        $response = $this->searchCharges([
            'filters' => [
                ['field' => 'issued_at', 'operator' => '>=', 'value' => '2025-01-01'],
                ['field' => 'issued_at', 'operator' => '<=', 'value' => '2025-12-31'],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($recent->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    public function test_owner_ids_filters_by_billing_owner(): void
    {
        $unitA = $this->createUnit();
        $unitB = $this->createUnit();

        // Central users — created outside tenant scope (no tenant_id column)
        $ownerA = User::create([
            'name' => 'Propietario A',
            'email' => Str::random(8).'@test.com',
            'auth_active' => true,
        ]);
        $ownerB = User::create([
            'name' => 'Propietario B',
            'email' => Str::random(8).'@test.com',
            'auth_active' => true,
        ]);

        // unit_user is tenant-scoped
        $this->tenant->run(function () use ($unitA, $unitB, $ownerA, $ownerB): void {
            UnitUser::create([
                'unit_id' => $unitA->id,
                'user_id' => $ownerA->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
            ]);
            UnitUser::create([
                'unit_id' => $unitB->id,
                'user_id' => $ownerB->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
            ]);
        });

        $chargeA = $this->createCharge($unitA);
        $chargeB = $this->createCharge($unitB);

        $response = $this->searchCharges([
            'owner_ids' => [$ownerA->id],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($chargeA->id, $ids);
        $this->assertNotContains($chargeB->id, $ids);
    }

    public function test_owner_ids_accepts_multiple_owners(): void
    {
        $unitA = $this->createUnit();
        $unitB = $this->createUnit();
        $unitC = $this->createUnit();

        $ownerA = User::create([
            'name' => 'Owner A',
            'email' => Str::random(8).'@test.com',
            'auth_active' => true,
        ]);
        $ownerB = User::create([
            'name' => 'Owner B',
            'email' => Str::random(8).'@test.com',
            'auth_active' => true,
        ]);

        $this->tenant->run(function () use ($unitA, $unitB, $ownerA, $ownerB): void {
            UnitUser::create(['unit_id' => $unitA->id, 'user_id' => $ownerA->id, 'unit_relationship_id' => UnitRelationship::OWNER, 'is_billing_owner' => true]);
            UnitUser::create(['unit_id' => $unitB->id, 'user_id' => $ownerB->id, 'unit_relationship_id' => UnitRelationship::OWNER, 'is_billing_owner' => true]);
        });

        $chargeA = $this->createCharge($unitA);
        $chargeB = $this->createCharge($unitB);
        $chargeC = $this->createCharge($unitC); // no billing owner — excluded

        $response = $this->searchCharges([
            'owner_ids' => [$ownerA->id, $ownerB->id],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($chargeA->id, $ids);
        $this->assertContains($chargeB->id, $ids);
        $this->assertNotContains($chargeC->id, $ids);
    }

    public function test_owner_ids_empty_array_returns_all_charges(): void
    {
        $unit = $this->createUnit();
        $charge = $this->createCharge($unit);

        $response = $this->searchCharges([
            'owner_ids' => [],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($charge->id, $ids);
    }

    public function test_owner_ids_omitted_returns_all_charges(): void
    {
        $unit = $this->createUnit();
        $charge = $this->createCharge($unit);

        $response = $this->searchCharges([]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($charge->id, $ids);
    }

    public function test_malformed_owner_ids_element_does_not_cause_500(): void
    {
        // A non-UUID string must not reach the PG uuid column and cause a 500 error.
        // The controller silently discards malformed entries; when all entries are
        // invalid it skips the filter entirely and returns all charges normally.
        $unit = $this->createUnit();
        $charge = $this->createCharge($unit);

        $response = $this->searchCharges([
            'owner_ids' => ['not-a-uuid', 'also-invalid-123', '<script>xss</script>'],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($charge->id, $ids);
    }

    /**
     * Regression (Ronda 3 #1): Orion text search must NOT include the UUID column
     * `unit_id` in searchableBy(), or Postgres throws "lower(uuid) does not exist"
     * (SQLSTATE 42883) → 500. searchableBy() = ['concept.name', 'concept.code'],
     * so searching by the concept name returns 200 and matches the charge.
     */
    public function test_text_search_by_concept_name_returns_200_not_500(): void
    {
        $unit = $this->createUnit();
        $charge = $this->createCharge($unit); // concept name "Test Filter Concept"

        $response = $this->searchCharges([
            'search' => ['value' => 'Test Filter Concept'],
            'includes' => [['relation' => 'concept']],
        ]);

        // The regression manifested as a 500; this asserts it is gone.
        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($charge->id, $ids);
    }

    public function test_text_search_by_concept_code_matches(): void
    {
        $unit = $this->createUnit();
        $charge = $this->createCharge($unit); // concept code "TEST-FILTER"

        $response = $this->searchCharges([
            'search' => ['value' => 'TEST-FILTER'],
            'includes' => [['relation' => 'concept']],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($charge->id, $ids);
    }

    /**
     * Estado filter (charges-filters): the frontend "Estado" MultiSelectFilter
     * sends charge_status_id with the `in` operator. charge_status_id is a direct
     * FK on charge and is listed in ChargeController::filterableBy().
     */
    public function test_filters_charges_by_charge_status_id_in(): void
    {
        $unit = $this->createUnit();

        $pending = $this->createCharge($unit, ['charge_status_id' => ChargeStatus::PENDING]);
        $overdue = $this->createCharge($unit, ['charge_status_id' => ChargeStatus::OVERDUE]);
        $completed = $this->createCharge($unit, [
            'expected_amount' => 20000,
            'paid_amount' => 20000,
            'charge_status_id' => ChargeStatus::COMPLETED,
        ]);

        $response = $this->searchCharges([
            'filters' => [
                ['field' => 'charge_status_id', 'operator' => 'in', 'value' => [ChargeStatus::PENDING, ChargeStatus::OVERDUE]],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($pending->id, $ids);
        $this->assertContains($overdue->id, $ids);
        $this->assertNotContains($completed->id, $ids);
    }

    /**
     * Concepto filter (charges-filters): the frontend "Concepto" MultiSelectFilter
     * sends charge_concept_id with the `in` operator. charge_concept_id is a direct
     * FK on charge (no nested charge_batch.charge_concept_id path needed) and is
     * listed in ChargeController::filterableBy().
     */
    public function test_filters_charges_by_charge_concept_id_in(): void
    {
        // A second concept so the filter has something to exclude.
        $otherConceptId = null;
        $this->tenant->run(function () use (&$otherConceptId): void {
            $categoryId = Catalog::id(ChargeConceptCategory::class, 'Ordinaria', $this->tenant);
            $other = ChargeConcept::create([
                'code' => 'TEST-FILTER-2',
                'name' => 'Second Filter Concept',
                'charge_concept_category_id' => $categoryId,
                'is_active' => true,
            ]);
            $otherConceptId = $other->id;
        });

        $unit = $this->createUnit();

        $primary = $this->createCharge($unit, ['charge_concept_id' => $this->conceptId]);
        $secondary = $this->createCharge($unit, ['charge_concept_id' => $otherConceptId]);

        $response = $this->searchCharges([
            'filters' => [
                ['field' => 'charge_concept_id', 'operator' => 'in', 'value' => [$this->conceptId]],
            ],
        ]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($primary->id, $ids);
        $this->assertNotContains($secondary->id, $ids);
    }
}
