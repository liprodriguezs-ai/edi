<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Models\ReceiptSequence;
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
 * Tests for receipt number (R-YYYY-NNNNNN) sequential generation.
 *
 * Covers:
 *  - 3 consecutive payments → R-2026-000001, R-2026-000002, R-2026-000003
 *  - Payment with paid_at in 2027 → R-2027-000001 (year reset, independent counter)
 *  - Two different tenants each start at R-YYYY-000001 (isolated counters)
 *  - Backfill: if a payment R-2026-000050 already exists when sequence row is absent,
 *    next sequence picks up from 51
 */
final class CollectionsReceiptSequenceTest extends TestCase
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

        Carbon::setTestNow();

        parent::tearDown();
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

    private function makeBankAccount(Tenant $tenant, int $initialBalance = 0): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $initialBalance): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => $initialBalance,
                'current_balance' => $initialBalance,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    /**
     * Create a charge for a unit and activate its batch. Must be called INSIDE tenant->run().
     */
    private function makeChargeForUnit(Unit $unit, int $amount, string $code, string $dueDate): Charge
    {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));

        $concept = ChargeConcept::create([
            'code' => $code,
            'name' => "Charge {$code}",
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        $policy = ChargePolicy::create([
            'charge_concept_id' => $concept->id,
            'name' => "Policy {$code}",
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => $amount,
            'periodicity_id' => Periodicity::EVENTUAL,
            'is_active' => true,
            'grace_days' => 0,
        ]);

        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
        $batch = ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => "Batch {$code}",
            'year' => 2026,
            'month' => null,
            'target_type' => 'single_unit',
            'target_selector' => ['unit_id' => $unit->id],
            'issued_at' => '2026-01-01',
            'due_date' => $dueDate,
            'charge_batch_status_id' => $draftStatus->id,
            'progress_pct' => 0,
        ]);

        app(ChargeGenerationService::class)->activateBatch($batch);

        return Charge::where('charge_batch_id', $batch->id)
            ->where('unit_id', $unit->id)
            ->firstOrFail();
    }

    /**
     * Register a confirmed payment and return the Payment model.
     * Must be called INSIDE tenant->run().
     */
    private function registerPayment(Unit $unit, BankAccount $bank, int $amount, string $paidAt): Payment
    {
        $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

        return app(PaymentService::class)->register([
            'unit_id' => $unit->id,
            'paid_at' => $paidAt,
            'total_amount' => $amount,
            'payment_method_id' => $paymentMethod->id,
            'bank_account_id' => $bank->id,
        ]);
    }

    // =========================================================================
    // 3 consecutive payments → sequential R-2026-000001 / -000002 / -000003
    // =========================================================================

    public function test_three_consecutive_payments_generate_sequential_receipt_numbers(): void
    {
        $tenant = $this->makeTenant('Sequential Receipt Tenant');
        $this->seedTenant($tenant);

        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);
        $unit3 = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit1, $unit2, $unit3, $bank): void {
            $this->makeChargeForUnit($unit1, 70000, 'SEQ1', '2099-12-31');
            $this->makeChargeForUnit($unit2, 70000, 'SEQ2', '2099-12-31');
            $this->makeChargeForUnit($unit3, 70000, 'SEQ3', '2099-12-31');

            $p1 = $this->registerPayment($unit1, $bank, 70000, '2026-01-10');
            $p2 = $this->registerPayment($unit2, $bank, 70000, '2026-01-10');
            $p3 = $this->registerPayment($unit3, $bank, 70000, '2026-01-10');

            $this->assertSame('R-2026-000001', $p1->receipt_number, 'First payment must be R-2026-000001');
            $this->assertSame('R-2026-000002', $p2->receipt_number, 'Second payment must be R-2026-000002');
            $this->assertSame('R-2026-000003', $p3->receipt_number, 'Third payment must be R-2026-000003');
        });
    }

    // =========================================================================
    // Payment in different year → R-2027-000001 (year counter reset)
    // =========================================================================

    public function test_payment_in_different_year_resets_to_000001(): void
    {
        $tenant = $this->makeTenant('YearReset Receipt Tenant');
        $this->seedTenant($tenant);

        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit1, $unit2, $bank): void {
            $this->makeChargeForUnit($unit1, 70000, 'YEAR26', '2099-12-31');
            $this->makeChargeForUnit($unit2, 70000, 'YEAR27', '2099-12-31');

            // 2026 payment
            $p1 = $this->registerPayment($unit1, $bank, 70000, '2026-12-31');
            $this->assertSame('R-2026-000001', $p1->receipt_number, '2026 payment must be R-2026-000001');

            // 2027 payment — independent year counter
            $p2 = $this->registerPayment($unit2, $bank, 70000, '2027-01-01');
            $this->assertSame('R-2027-000001', $p2->receipt_number, '2027 payment must start at R-2027-000001');
        });
    }

    // =========================================================================
    // Two different tenants → each starts at R-YYYY-000001
    // =========================================================================

    public function test_two_tenants_have_independent_receipt_sequences(): void
    {
        $tenantA = $this->makeTenant('Receipt Tenant A');
        $tenantB = $this->makeTenant('Receipt Tenant B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);
        $bankA = $this->makeBankAccount($tenantA, 0);
        $bankB = $this->makeBankAccount($tenantB, 0);

        // Tenant A registers 2 payments
        $tenantA->run(function () use ($unitA, $bankA): void {
            $this->makeChargeForUnit($unitA, 70000, 'TA1', '2099-12-31');
            $pA1 = $this->registerPayment($unitA, $bankA, 35000, '2026-01-10');
            $this->assertSame('R-2026-000001', $pA1->receipt_number, 'Tenant A first payment');

            $this->makeChargeForUnit($unitA, 70000, 'TA2', '2099-12-31');
            $pA2 = $this->registerPayment($unitA, $bankA, 35000, '2026-01-11');
            $this->assertSame('R-2026-000002', $pA2->receipt_number, 'Tenant A second payment');
        });

        // Tenant B must also start at R-2026-000001, independent of A's counter
        $tenantB->run(function () use ($unitB, $bankB): void {
            $this->makeChargeForUnit($unitB, 70000, 'TB1', '2099-12-31');
            $pB1 = $this->registerPayment($unitB, $bankB, 35000, '2026-01-10');
            $this->assertSame('R-2026-000001', $pB1->receipt_number, 'Tenant B must start at R-2026-000001 independent of tenant A');
        });
    }

    // =========================================================================
    // Backfill: when sequence row is absent but a payment R-2026-000050 already exists,
    // the next sequence is 000051
    // =========================================================================

    public function test_sequence_backfills_from_existing_max_receipt_number(): void
    {
        $tenant = $this->makeTenant('Backfill Receipt Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit, $bank): void {
            $this->makeChargeForUnit($unit, 70000, 'BF1', '2099-12-31');

            // Manually insert a payment with receipt_number R-2026-000050
            // to simulate pre-existing data before the sequence counter existed.
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            Payment::create([
                'unit_id' => $unit->id,
                'bank_account_id' => $bank->id,
                'total_amount' => 50000,
                'paid_at' => '2026-01-01',
                'receipt_number' => 'R-2026-000050',
                'payment_method_id' => $paymentMethod->id,
                'payment_status_id' => PaymentStatus::CONFIRMED,
                'notes' => 'Backfill test payment',
            ]);

            // No receipt_sequence row exists yet for 2026 (we skipped normal registration)
            $this->assertNull(ReceiptSequence::where('year', 2026)->first(), 'Precondition: no sequence row yet');

            // Register a new payment — should detect max existing = 50 and assign 51
            $payment = $this->registerPayment($unit, $bank, 70000, '2026-06-01');
            $this->assertSame('R-2026-000051', $payment->receipt_number, 'Backfill must pick up after existing max R-2026-000050 → next is R-2026-000051');
        });
    }
}
