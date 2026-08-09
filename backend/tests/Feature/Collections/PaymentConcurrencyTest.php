<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Financial-correctness invariants for payment application. PaymentService uses
 * lockForUpdate() throughout to prevent double-application, but nothing pins the
 * resulting invariant — a refactor dropping a lock would stay green. These tests
 * pin it: a charge's paid_amount can never exceed expected_amount, surplus
 * becomes credit, and no duplicate payment_application exists per (payment, charge).
 */
final class PaymentConcurrencyTest extends TestCase
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

    public function test_second_payment_surplus_becomes_credit_not_overapplication(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant);
        $policy = $this->makeConcept($tenant, 70000);

        $tenant->run(function () use ($policy, $unit, $bank): void {
            $charge = $this->activateSingleCharge($policy, $unit, 'Charge A');
            $cash = PaymentMethod::findOrFail(PaymentMethod::CASH)->id;
            $service = app(PaymentService::class);

            // First payment covers part of the 70000 charge.
            $service->register([
                'unit_id' => $unit->id, 'paid_at' => '2026-01-10', 'total_amount' => 50000,
                'payment_method_id' => $cash, 'bank_account_id' => $bank->id,
            ]);

            // Second payment (40000) covers the remaining 20000; 20000 surplus → credit.
            $service->register([
                'unit_id' => $unit->id, 'paid_at' => '2026-01-11', 'total_amount' => 40000,
                'payment_method_id' => $cash, 'bank_account_id' => $bank->id,
            ]);

            $charge->refresh();
            $paid = (int) $charge->getAttributes()['paid_amount'];
            $expected = (int) $charge->getAttributes()['expected_amount'];

            $this->assertSame(70000, $expected);
            $this->assertSame(70000, $paid, 'paid_amount must equal expected, never exceed it');
            $this->assertLessThanOrEqual($expected, $paid, 'paid_amount must never exceed expected_amount');
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id);

            $credit = CreditBalance::where('unit_id', $unit->id)->first();
            $this->assertNotNull($credit, 'Surplus must become a credit balance');
            $this->assertSame(20000, (int) $credit->getAttributes()['amount'], 'Surplus 20000 must be credit, not over-applied');
        });
    }

    public function test_no_duplicate_payment_application_per_payment_and_charge(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant);
        $policy = $this->makeConcept($tenant, 30000);

        $tenant->run(function () use ($policy, $unit, $bank): void {
            $this->activateSingleCharge($policy, $unit, 'Charge B');
            $cash = PaymentMethod::findOrFail(PaymentMethod::CASH)->id;
            $service = app(PaymentService::class);

            // Register the same logical payment twice.
            foreach (['2026-02-01', '2026-02-02'] as $date) {
                $service->register([
                    'unit_id' => $unit->id, 'paid_at' => $date, 'total_amount' => 30000,
                    'payment_method_id' => $cash, 'bank_account_id' => $bank->id,
                ]);
            }

            // No (payment_id, charge_id) pair may appear more than once.
            $dupes = DB::table('payment_application')
                ->select('payment_id', 'charge_id', DB::raw('count(*) as n'))
                ->groupBy('payment_id', 'charge_id')
                ->havingRaw('count(*) > 1')
                ->get();

            $this->assertCount(0, $dupes, 'A payment must apply to a given charge at most once');
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function makeBankAccount(Tenant $tenant): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    private function makeConcept(Tenant $tenant, int $amount): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $amount): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => 'CONC_'.Str::upper(Str::random(5)),
                'name' => 'Concurrency concept',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Concurrency policy',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => $amount,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'grace_days' => 0,
            ]);
        });

        return $policy;
    }

    /**
     * Create + activate a single-unit charge batch. Must run inside $tenant->run().
     */
    private function activateSingleCharge(ChargePolicy $policy, Unit $unit, string $title): Charge
    {
        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
        $batch = ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => $title,
            'year' => 2026,
            'month' => null,
            'target_type' => 'single_unit',
            'target_selector' => ['unit_id' => $unit->id],
            'issued_at' => '2026-01-01',
            'due_date' => '2099-12-31',
            'charge_batch_status_id' => $draftStatus->id,
            'progress_pct' => 0,
        ]);
        app(ChargeGenerationService::class)->activateBatch($batch);

        return Charge::where('charge_batch_id', $batch->id)->firstOrFail();
    }
}
