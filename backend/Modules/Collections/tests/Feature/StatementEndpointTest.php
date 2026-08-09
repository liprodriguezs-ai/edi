<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * GET /unit/{id}/statement — the resident account statement.
 *
 * Asserts the two guarantees of the pagination refactor:
 *  - `charges` now ships ONLY pending charges (non-cancelled, principal balance > 0), while the
 *    totals still aggregate over ALL non-cancelled charges (fully-paid history counts in `paid`).
 *  - the cached totals are invalidated by StatementTotalsCacheObserver when a payment lands, so a
 *    second read reflects the new balance instead of a stale cached one.
 *
 * $connectionsToTransact = [] so PaymentService's DB::transaction really commits and the observer's
 * ShouldHandleEventsAfterCommit forget actually fires (as it does in production).
 */
final class StatementEndpointTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Statement Tenant');
        $this->seedTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_statement_ships_only_pending_charges_but_totals_cover_all_open_charges(): void
    {
        $unitId = null;

        $this->tenant->run(function () use (&$unitId): void {
            $unit = $this->makeUnit();
            $unitId = $unit->id;
            $concept = $this->makeConcept();

            // Fully paid -> NOT pending, but counts toward expected + paid.
            $this->makeCharge($concept, $unit, 100000, 100000);
            // Partial -> pending, owes 60000.
            $this->makeCharge($concept, $unit, 100000, 40000);
            // Unpaid -> pending, owes 100000.
            $this->makeCharge($concept, $unit, 100000, 0);
            // Cancelled -> excluded from every figure and from the list.
            $this->makeCharge($concept, $unit, 999999, 0, cancelled: true);
        });

        $this->tenantRequest($this->adminToken(), $this->tenant)
            ->getJson("/api/unit/{$unitId}/statement")
            ->assertOk()
            // expected over the 3 non-cancelled charges.
            ->assertJsonPath('totals.expected.amount', '300000')
            ->assertJsonPath('totals.expected.currency', 'MXN')
            // paid over non-cancelled (incl. the fully-paid one).
            ->assertJsonPath('totals.paid.amount', '140000')
            ->assertJsonPath('totals.paid.currency', 'MXN')
            // outstanding: 0 + 60000 + 100000.
            ->assertJsonPath('totals.outstanding.amount', '160000')
            ->assertJsonPath('totals.outstanding.currency', 'MXN')
            // list = pending only: partial + unpaid.
            ->assertJsonCount(2, 'charges');
    }

    public function test_totals_cache_is_invalidated_when_a_payment_is_applied(): void
    {
        $unitId = null;

        $this->tenant->run(function () use (&$unitId): void {
            $unit = $this->makeUnit();
            $unitId = $unit->id;
            $concept = $this->makeConcept();
            $this->makeCharge($concept, $unit, 100000, 0); // owes 1000.00
        });

        $token = $this->adminToken();

        // First read populates the per-unit totals cache.
        $this->tenantRequest($token, $this->tenant)
            ->getJson("/api/unit/{$unitId}/statement")
            ->assertOk()
            ->assertJsonPath('totals.outstanding.amount', '100000')
            ->assertJsonPath('totals.outstanding.currency', 'MXN')
            ->assertJsonPath('totals.paid.amount', '0')
            ->assertJsonPath('totals.paid.currency', 'MXN');

        // Apply a full payment: the Charge save fires the observer, which forgets the cache.
        $this->tenant->run(function () use ($unitId): void {
            $bank = $this->makeBankAccount();
            app(PaymentService::class)->register([
                'unit_id' => $unitId,
                'paid_at' => '2026-06-20',
                'total_amount' => 100000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $bank->id,
            ], true);
        });

        // Second read must reflect the payment -> proves the cache was invalidated, not served stale.
        $this->tenantRequest($token, $this->tenant)
            ->getJson("/api/unit/{$unitId}/statement")
            ->assertOk()
            ->assertJsonPath('totals.outstanding.amount', '0')
            ->assertJsonPath('totals.paid.amount', '100000')
            ->assertJsonPath('totals.paid.currency', 'MXN')
            ->assertJsonCount(0, 'charges'); // fully paid -> no longer pending
    }

    public function test_totals_surcharge_is_present_as_money_object(): void
    {
        $unitId = null;

        $this->tenant->run(function () use (&$unitId): void {
            $unit = $this->makeUnit();
            $unitId = $unit->id;
            $concept = $this->makeConcept();
            // Unpaid charge with an accrued late-fee surcharge.
            $this->makeCharge($concept, $unit, 100000, 0, surcharge: 5000);
        });

        $this->tenantRequest($this->adminToken(), $this->tenant)
            ->getJson("/api/unit/{$unitId}/statement")
            ->assertOk()
            ->assertJsonPath('totals.surcharge.amount', '5000')
            ->assertJsonPath('totals.surcharge.currency', 'MXN')
            // outstanding = max(0, expected - discount) + surcharge - paid = 100000 + 5000.
            ->assertJsonPath('totals.outstanding.amount', '105000');
    }

    private function makeUnit(): Unit
    {
        return Unit::create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => (string) mt_rand(1, 999999),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            'ownership_share' => 1.0,
        ]);
    }

    private function makeConcept(): ChargeConcept
    {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

        return ChargeConcept::create([
            'code' => 'STMT_'.strtoupper(substr(md5(uniqid()), 0, 6)),
            'name' => 'Statement concept',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);
    }

    private function makeCharge(ChargeConcept $concept, Unit $unit, int $expected, int $paid, bool $cancelled = false, int $surcharge = 0): Charge
    {
        return Charge::create([
            'charge_concept_id' => $concept->id,
            'unit_id' => $unit->id,
            'expected_amount' => $expected,
            'paid_amount' => $paid,
            'surcharge_amount' => $surcharge,
            'issued_at' => '2026-06-01',
            'due_date' => '2026-06-10',
            'grace_days' => 0,
            'charge_status_id' => $cancelled ? ChargeStatus::OVERDUE : ChargeStatus::PENDING,
            'is_cancelled' => $cancelled,
        ]);
    }

    private function makeBankAccount(): BankAccount
    {
        return BankAccount::create([
            'name' => 'Caja Statement',
            'bank_account_type_id' => BankAccountType::CASH,
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    private function adminToken(): string
    {
        // Wildcard token: exercises the aggregate + cache, not permission wiring.
        return $this->makeUser($this->tenant)->createToken('admin', ['*'])->plainTextToken;
    }
}
