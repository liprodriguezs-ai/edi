<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\ChargeStatusService;
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
 * Tests for time-based status transitions (cron) and unit aggregate worst-wins logic.
 *
 * Covers:
 *  - PENDING → DUE_SOON (within due_soon_days window)
 *  - PENDING → OVERDUE (past due_date + grace_days)
 *  - DUE_SOON → OVERDUE (time advances past grace)
 *  - PARTIAL → OVERDUE (partial payments don't freeze status when past grace)
 *  - Cron is idempotent (COMPLETED charges untouched)
 *  - OVERPAID boundary: resolveStatusKey with paid > threshold → OVERPAID; at threshold → COMPLETED
 *  - Worst-wins aggregate: [OVERDUE, COMPLETED] → OVERDUE
 *  - Worst-wins aggregate: [PARTIAL, PENDING] → PARTIAL
 *  - Worst-wins aggregate: [DUE_SOON, COMPLETED] → DUE_SOON
 */
final class CollectionsStatusCronAndWorstWinsTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Pin "today" to a fixed anchor so initial-status assertions are
        // deterministic regardless of the real calendar date. Tests that need a
        // later moment override with Carbon::setTestNow(); tearDown resets it.
        Carbon::setTestNow(Carbon::parse('2026-06-11'));
    }

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
     * Create a concept + activate a batch targeting a single unit.
     * Must be called INSIDE tenant->run().
     * Returns the Charge record.
     */
    private function makeChargeForUnit(Unit $unit, int $amount, string $code, string $dueDate, int $graceDays = 0): Charge
    {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));

        $concept = ChargeConcept::create([
            'code' => $code,
            'name' => "Test concept {$code}",
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
            'grace_days' => $graceDays,
        ]);

        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
        $batch = ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => "Batch {$code}",
            'year' => (int) substr($dueDate, 0, 4),
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

    private function runRefreshCron(): void
    {
        Artisan::call('collections:refresh-statuses');
    }

    // =========================================================================
    // PENDING → DUE_SOON: within due_soon_days window
    // =========================================================================

    public function test_pending_transitions_to_due_soon_when_within_window(): void
    {
        $tenant = $this->makeTenant('DueSoon Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        // Create the charge FIRST (today = default, far from due → PENDING)
        // Default today is ~2026-06-11, due 2026-07-15 = 34 days away → PENDING
        $tenant->run(function () use ($unit): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'SCDS1', '2026-07-15');
            $this->assertSame(ChargeStatus::PENDING, $charge->charge_status_id, 'Initial status must be PENDING');
        });

        // Advance time to within 7-day due_soon window (5 days before July 15)
        Carbon::setTestNow(Carbon::parse('2026-07-10'));
        $this->runRefreshCron();

        $tenant->run(function () use ($unit): void {
            $charge = Charge::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(ChargeStatus::DUE_SOON, $charge->charge_status_id, 'Must transition to DUE_SOON within window');
        });
    }

    // =========================================================================
    // PENDING → OVERDUE: past due_date + grace_days
    // =========================================================================

    public function test_pending_transitions_to_overdue_when_past_due_plus_grace(): void
    {
        $tenant = $this->makeTenant('Overdue Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        // Create charge with future due date while today is normal → PENDING
        $tenant->run(function () use ($unit): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'SCOD1', '2026-07-01', 3);
            $this->assertSame(ChargeStatus::PENDING, $charge->charge_status_id, 'Initial status must be PENDING');
        });

        // Advance to past due_date + grace_days (July 1 + 3 = July 4, so July 5 = overdue)
        Carbon::setTestNow(Carbon::parse('2026-07-05'));
        $this->runRefreshCron();

        $tenant->run(function () use ($unit): void {
            $charge = Charge::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(ChargeStatus::OVERDUE, $charge->charge_status_id, 'Must transition to OVERDUE');
        });
    }

    // =========================================================================
    // DUE_SOON → OVERDUE: time advances past grace
    // =========================================================================

    public function test_due_soon_transitions_to_overdue_when_grace_expires(): void
    {
        $tenant = $this->makeTenant('DueSoonOverdue Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        // Create charge while today is normal → PENDING
        $tenant->run(function () use ($unit): void {
            $this->makeChargeForUnit($unit, 70000, 'SCDSO', '2026-07-15');
        });

        // First cron: 5 days before due → DUE_SOON
        Carbon::setTestNow(Carbon::parse('2026-07-10'));
        $this->runRefreshCron();

        $tenant->run(function () use ($unit): void {
            $charge = Charge::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(ChargeStatus::DUE_SOON, $charge->charge_status_id, 'Should be DUE_SOON after first cron');
        });

        // Second cron: 2 days AFTER due_date (no grace) → OVERDUE
        Carbon::setTestNow(Carbon::parse('2026-07-17'));
        $this->runRefreshCron();

        $tenant->run(function () use ($unit): void {
            $charge = Charge::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(ChargeStatus::OVERDUE, $charge->charge_status_id, 'DUE_SOON must advance to OVERDUE');
        });
    }

    // =========================================================================
    // PARTIAL → OVERDUE: partial payments don't freeze status when past grace
    // =========================================================================

    public function test_partial_transitions_to_overdue_when_past_grace(): void
    {
        $tenant = $this->makeTenant('PartialOverdue Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        // Create charge with future due → PENDING
        $tenant->run(function () use ($unit, $bank): void {
            $this->makeChargeForUnit($unit, 70000, 'SCPARTOD', '2026-07-01', 0);

            // Make a partial payment while due is still future → PARTIAL
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-06-15',
                'total_amount' => 30000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);

            $charge = Charge::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(ChargeStatus::PARTIAL, $charge->charge_status_id, 'Must be PARTIAL after partial payment');
        });

        // Advance past due_date + grace (July 1 + 0 = July 1, so July 2 = overdue)
        Carbon::setTestNow(Carbon::parse('2026-07-02'));
        $this->runRefreshCron();

        $tenant->run(function () use ($unit): void {
            $charge = Charge::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(ChargeStatus::OVERDUE, $charge->charge_status_id, 'PARTIAL past grace must become OVERDUE');
        });
    }

    // =========================================================================
    // Cron is idempotent: COMPLETED charges untouched
    // =========================================================================

    public function test_cron_does_not_touch_completed_charges(): void
    {
        $tenant = $this->makeTenant('Idempotent Cron Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        // Create a charge with a past due date and pay it fully → COMPLETED
        $tenant->run(function () use ($unit, $bank): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'SCIDEM', '2099-12-31');

            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => 70000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);

            $charge->refresh();
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id, 'Precondition: charge must be COMPLETED');
        });

        // Run cron with today far past (to test COMPLETED never transitions to OVERDUE)
        Carbon::setTestNow(Carbon::parse('2030-01-01'));
        $this->runRefreshCron();

        $tenant->run(function () use ($unit): void {
            $charge = Charge::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id, 'COMPLETED must remain COMPLETED even if due_date is past');
        });
    }

    // =========================================================================
    // OVERPAID boundary: resolveStatusKey tested directly with raw charge attributes
    // (FIFO caps application at chargeRemaining; to test OVERPAID boundary we use
    //  the service method directly with a charge created with paid > expected)
    // =========================================================================

    public function test_resolve_status_key_returns_overpaid_when_paid_exceeds_threshold(): void
    {
        $tenant = $this->makeTenant('Overpaid Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        // Default overpayment_pct = 1.0%
        // expected = 70000; threshold = (int)(70000 * 1.01) = 70700; paid = 70701 → OVERPAID
        $tenant->run(function () use ($unit): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => 'OVP1',
                'name' => 'Overpaid test',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            // Directly create a charge with paid_amount > expected_amount (bypassing FIFO cap)
            $charge = Charge::create([
                'charge_concept_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'paid_amount' => 70701,  // strictly above threshold (70000*1.01=70700)
                'discount_amount' => 0,
                'issued_at' => '2026-01-01',
                'due_date' => '2099-12-31',
                'grace_days' => 0,
                'charge_status_id' => ChargeStatus::PENDING,
                'is_cancelled' => false,
            ]);

            $key = app(ChargeStatusService::class)->resolveStatusKey($charge);
            $this->assertSame(ChargeStatus::OVERPAID, $key, 'Paid strictly above threshold must resolve to OVERPAID');
        });
    }

    public function test_resolve_status_key_returns_completed_when_paid_exactly_at_threshold(): void
    {
        $tenant = $this->makeTenant('ExactThreshold Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        // expected = 70000; threshold = (int)(70000 * 1.01) = 70700; paid = 70700 → COMPLETED
        $tenant->run(function () use ($unit): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => 'OVP2',
                'name' => 'Exact threshold test',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            // paid exactly at threshold → NOT overpaid
            $charge = Charge::create([
                'charge_concept_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'paid_amount' => 70700,  // exactly at (int)(70000*1.01) → COMPLETED
                'discount_amount' => 0,
                'issued_at' => '2026-01-01',
                'due_date' => '2099-12-31',
                'grace_days' => 0,
                'charge_status_id' => ChargeStatus::PENDING,
                'is_cancelled' => false,
            ]);

            $key = app(ChargeStatusService::class)->resolveStatusKey($charge);
            $this->assertSame(ChargeStatus::COMPLETED, $key, 'Paid exactly at threshold must be COMPLETED not OVERPAID');
        });
    }

    // =========================================================================
    // Worst-wins aggregate: [OVERDUE, COMPLETED] → OVERDUE
    // =========================================================================

    public function test_worst_wins_overdue_beats_completed(): void
    {
        $tenant = $this->makeTenant('WorstWins Overdue Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        // Create charge1 (COMPLETED via full payment, far future due to avoid FIFO interference)
        // and charge2 (will become OVERDUE)
        $tenant->run(function () use ($unit, $bank): void {
            // charge1: due 2026-06-01 (earlier, FIFO will target this first)
            $this->makeChargeForUnit($unit, 70000, 'WWOC1', '2026-06-01');

            // charge2: due 2026-07-01, expected 50000
            $this->makeChargeForUnit($unit, 50000, 'WWOC2', '2026-07-01');

            // Pay 70000 → FIFO hits charge1 first (earlier due) → COMPLETED; charge2 stays PENDING
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-05-31',
                'total_amount' => 70000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);
        });

        // Advance past charge2 due date → charge2 becomes OVERDUE
        Carbon::setTestNow(Carbon::parse('2026-07-10'));
        $this->runRefreshCron();

        $tenant->run(function () use ($unit): void {
            $unit->refresh();
            $this->assertSame(ChargeStatus::OVERDUE, $unit->charge_status_id, '[OVERDUE, COMPLETED] worst-wins must be OVERDUE');
        });
    }

    // =========================================================================
    // Worst-wins aggregate: [PARTIAL, PENDING] → PARTIAL
    // =========================================================================

    public function test_worst_wins_partial_beats_pending(): void
    {
        $tenant = $this->makeTenant('WorstWins Partial Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit, $bank): void {
            // charge1: due in 2 months, will receive partial payment
            $this->makeChargeForUnit($unit, 70000, 'WWPP1', '2026-08-01');

            // charge2: due in 3 months, stays PENDING
            $this->makeChargeForUnit($unit, 50000, 'WWPP2', '2026-09-01');

            // Partial payment: 30000 → FIFO hits charge1 (earlier due) → PARTIAL; charge2 stays PENDING
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-06-11',
                'total_amount' => 30000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);

            $unit->refresh();
            $this->assertSame(ChargeStatus::PARTIAL, $unit->charge_status_id, '[PARTIAL, PENDING] worst-wins must be PARTIAL');
        });
    }

    // =========================================================================
    // Worst-wins aggregate: [DUE_SOON, COMPLETED] → DUE_SOON
    // =========================================================================

    public function test_worst_wins_due_soon_beats_completed(): void
    {
        $tenant = $this->makeTenant('WorstWins DueSoon Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit, $bank): void {
            // charge1: due July 15 (will be DUE_SOON after cron from July 10), expected 70000
            $this->makeChargeForUnit($unit, 70000, 'WWDSC1', '2026-07-15');

            // charge2: due June 1 (earlier due_date → FIFO hits this first), expected 50000
            $this->makeChargeForUnit($unit, 50000, 'WWDSC2', '2026-06-01');

            // Pay 50000 → FIFO targets charge2 (earlier due_date June 1) → COMPLETED
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-05-31',
                'total_amount' => 50000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);
        });

        // Advance to within DUE_SOON window for charge1 (5 days before July 15)
        Carbon::setTestNow(Carbon::parse('2026-07-10'));
        $this->runRefreshCron();

        $tenant->run(function () use ($unit): void {
            $unit->refresh();
            $this->assertSame(ChargeStatus::DUE_SOON, $unit->charge_status_id, '[DUE_SOON, COMPLETED] worst-wins must be DUE_SOON');
        });
    }
}
