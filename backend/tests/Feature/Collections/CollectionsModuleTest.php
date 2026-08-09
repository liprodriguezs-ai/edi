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
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\AmountCalculator;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\PaymentService;
use Modules\Collections\Services\TargetResolver;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 4 — Collections (Cobranza).
 *
 * THE 4 MANDATORY SPEC FLOWS (§8.8):
 *  A. Mass monthly generation
 *  B. Annual prepay (Flujo B: Sra. Dina — 12 months prepaid)
 *  C. Overpayment → FIFO + credit
 *  D. Single-unit fine (ad-hoc charge)
 *
 * Plus:
 *  - ChargeStatusService: all 6 states incl. grace days + due_soon threshold
 *  - FIFO ordering (due_date then issued_at)
 *  - Payment cancel reverses applications + restores credit + decrements bank balance
 *  - bank_account.current_balance updated on confirmed payment
 *  - Charge cancel requires reason + keeps history
 *  - Tenant isolation on every new table
 *  - Unique receipt_number per tenant
 *  - Money round-trips as minor units (integer storage)
 */
final class CollectionsModuleTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /**
     * End tenancy after each test so the tenant connection does not linger and
     * race with RefreshDatabase's TRUNCATE ... CASCADE on the `tenant` table
     * (which otherwise causes a Postgres deadlock across the central + tenant
     * connections). Mirrors the pattern in the other tenant-scoped test suites.
     */
    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================
    // Test helpers
    // =========================================================================

    private function makeUnit(Tenant $tenant, ?float $ownershipShare = null): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit, $ownershipShare): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(100, 9999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => $ownershipShare ?? 1.2987,
            ]);
        });

        return $unit;
    }

    private function makeBankAccount(Tenant $tenant, int $initialBalanceRaw = 0): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $initialBalanceRaw): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => $initialBalanceRaw,
                'current_balance' => $initialBalanceRaw,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    /**
     * Create a ChargeConcept with fixed_required + monthly periodicity.
     * fixed_amount in MINOR UNITS (e.g. 70000 = $700 MXN).
     */
    /**
     * @param  string  $validFrom  ANCHORED BILLING PERIODS: a virgin policy's cron chain
     *                             seeds from valid_from and walks EVERY missed period up to
     *                             today (capped catch-up) — not just "today's" period like
     *                             the old calendar-exact-match model. Callers that exercise
     *                             the cron on a date far past the default '2026-01-01' MUST
     *                             pass a valid_from close to their test date, or they will
     *                             unintentionally trigger a multi-period catch-up burst.
     */
    private function makeMonthlyFixedConcept(Tenant $tenant, int $fixedAmountRaw, string $validFrom = '2026-01-01'): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $fixedAmountRaw, $validFrom): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

            $concept = ChargeConcept::create([
                'code' => 'MANT_'.mt_rand(1000, 99999),
                'name' => 'Mantenimiento mensual',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Política mensual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => $fixedAmountRaw,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => $validFrom,
                'is_active' => true,
                'grace_days' => 0,
            ]);

            // A periodic policy is configured 1-to-1 with its INITIAL (template) batch:
            // the daily cron clones it each period. Anchor the template at an OFF-test
            // period (2025-01) targeting ALL units so the cron's clones for the periods
            // these tests exercise (2026+) are distinct, non-initial batches. Created as a
            // DRAFT (no charges) — the cron reads only its target config when cloning.
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Plantilla inicial',
                'year' => 2025,
                'month' => 1,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2025-01-01',
                'due_date' => '2025-01-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
                'is_initial' => true,
            ]);
        });

        return $policy;
    }

    private function makeEventualFixedConcept(Tenant $tenant, int $fixedAmountRaw, string $code = 'MULTA_TEST'): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $fixedAmountRaw, $code): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));

            $concept = ChargeConcept::create([
                'code' => $code,
                'name' => 'Multa',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Política eventual',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => $fixedAmountRaw,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'grace_days' => 0,
            ]);
        });

        return $policy;
    }

    // =========================================================================
    // SPEC FLOW A: Mass monthly generation
    // =========================================================================

    public function test_flow_a_mass_monthly_generation(): void
    {
        $tenant = $this->makeTenant('Flow A Tenant');
        $this->seedTenant($tenant);

        $fixedAmountRaw = 70000; // $700 in minor units

        $units = [];
        for ($i = 0; $i < 5; $i++) {
            $units[] = $this->makeUnit($tenant);
        }

        $concept = $this->makeMonthlyFixedConcept($tenant, $fixedAmountRaw);

        $tenant->run(function () use ($concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => 'Mantenimiento Marzo 2026',
                'year' => 2026,
                'month' => 3,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2026-03-01',
                'due_date' => '2026-03-15',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            // Activate the batch (generate charges).
            $generationService = app(ChargeGenerationService::class);
            $activatedBatch = $generationService->activateBatch($batch);

            // Assert batch is now active.
            $activatedBatch->refresh();
            $activeStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::ACTIVE);
            $this->assertSame($activeStatus->id, $activatedBatch->charge_batch_status_id);

            // Assert exactly 5 charges created (one per unit).
            $charges = Charge::where('charge_batch_id', $activatedBatch->id)->get();
            $this->assertCount(5, $charges);

            // Assert each charge has expected_amount = 70000 (minor units) — MONEY ROUND-TRIP.
            foreach ($charges as $charge) {
                $rawExpected = (int) $charge->getAttributes()['expected_amount'];
                $this->assertSame(70000, $rawExpected, 'expected_amount must be stored as integer minor units');
                $this->assertSame(0, (int) $charge->getAttributes()['paid_amount']);

                // Each charge should be in pending or due_soon status (date-dependent in test).
                $statusId = $charge->charge_status_id;
                $this->assertContains($statusId, [
                    ChargeStatus::PENDING,
                    ChargeStatus::DUE_SOON,
                    ChargeStatus::OVERDUE,
                ], "Charge status must be pending/due_soon/overdue after generation; got {$statusId}");
            }

        });
    }

    // =========================================================================
    // SPEC FLOW B: Annual prepay (Sra. Dina — 12 charges, 1 payment)
    // =========================================================================

    public function test_flow_b_annual_prepay_fifo_12_months(): void
    {
        $tenant = $this->makeTenant('Flow B Tenant');
        $this->seedTenant($tenant);

        $fixedAmountRaw = 70000; // $700 each
        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeMonthlyFixedConcept($tenant, $fixedAmountRaw);

        $tenant->run(function () use ($concept, $unit, $bankAccount): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $pendingStatus = ChargeStatus::findOrFail(ChargeStatus::PENDING);
            $generationService = app(ChargeGenerationService::class);

            // Create 12 monthly charges (Jan–Dec 2026) for this unit.
            $chargeIds = [];
            for ($month = 1; $month <= 12; $month++) {
                $dueDate = sprintf('2026-%02d-15', $month);
                $issuedAt = sprintf('2026-%02d-01', $month);

                $batch = ChargeBatch::create([
                    'charge_policy_id' => $concept->id,
                    'title' => "Mantenimiento mes {$month} 2026",
                    'year' => 2026,
                    'month' => $month,
                    'target_type' => 'single_unit',
                    'target_selector' => ['unit_id' => $unit->id],
                    'issued_at' => $issuedAt,
                    'due_date' => $dueDate,
                    'charge_batch_status_id' => $draftStatus->id,
                    'progress_pct' => 0,
                ]);

                $generationService->activateBatch($batch);

                $charge = Charge::where('charge_batch_id', $batch->id)
                    ->where('unit_id', $unit->id)
                    ->firstOrFail();
                $chargeIds[] = $charge->id;
            }

            $this->assertCount(12, $chargeIds, '12 charges must be created');

            // Register ONE payment of $8,400 (840000 minor units).
            $totalPaymentRaw = 840000; // 12 × 70000

            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            $paymentService = app(PaymentService::class);

            $payment = $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => $totalPaymentRaw,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // Assert receipt_number generated with correct format.
            $this->assertMatchesRegularExpression('/^R-2026-\d{6}$/', $payment->receipt_number);
            $this->assertSame('R-2026-000001', $payment->receipt_number);

            // Assert 12 payment_application rows.
            $applications = PaymentApplication::where('payment_id', $payment->id)->get();
            $this->assertCount(12, $applications, 'FIFO must create 12 payment applications for 12 charges');

            // Assert each application = 70000 minor units.
            foreach ($applications as $app) {
                $this->assertSame(70000, (int) $app->getAttributes()['applied_amount']);
            }

            // Assert all 12 charges are now COMPLETED.
            $completedStatus = ChargeStatus::findOrFail(ChargeStatus::COMPLETED);
            foreach ($chargeIds as $chargeId) {
                $charge = Charge::find($chargeId);
                $this->assertSame(
                    $completedStatus->id,
                    $charge->charge_status_id,
                    "Charge {$chargeId} must be completed after full payment"
                );
                $this->assertSame(70000, (int) $charge->getAttributes()['paid_amount']);
            }

            // Assert credit_balance = 0 (no overpayment).
            $credit = CreditBalance::where('unit_id', $unit->id)->first();
            $this->assertTrue(
                $credit === null || (int) $credit->getAttributes()['amount'] === 0,
                'No credit should remain when payment exactly covers all charges'
            );

            // Assert bank balance updated.
            $bankAccount->refresh();
            $this->assertSame($totalPaymentRaw, (int) $bankAccount->getAttributes()['current_balance']);
        });
    }

    // =========================================================================
    // SPEC FLOW C: Overpayment → FIFO + credit → next charge uses credit
    // =========================================================================

    public function test_flow_c_overpayment_fifo_credit(): void
    {
        $tenant = $this->makeTenant('Flow C Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'CHARGE_C1');

        $tenant->run(function () use ($unit, $bankAccount, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);

            // Create 2 charges of $700 each (total $1,400 = 140000 minor units).
            $chargeIds = [];
            for ($i = 1; $i <= 2; $i++) {
                $due = sprintf('2026-0%d-15', $i);
                $issued = sprintf('2026-0%d-01', $i);

                $batch = ChargeBatch::create([
                    'charge_policy_id' => $concept->id,
                    'title' => "Cargo {$i}",
                    'year' => 2026,
                    'month' => null, // eventual
                    'target_type' => 'single_unit',
                    'target_selector' => ['unit_id' => $unit->id],
                    'issued_at' => $issued,
                    'due_date' => $due,
                    'charge_batch_status_id' => $draftStatus->id,
                    'progress_pct' => 0,
                ]);

                $generationService->activateBatch($batch);

                $charge = Charge::where('charge_batch_id', $batch->id)
                    ->where('unit_id', $unit->id)
                    ->firstOrFail();
                $chargeIds[] = $charge->id;
            }

            // Pay $1,500 (150000 minor units) — $100 overpayment.
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $payment = $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-10',
                'total_amount' => 150000, // $1,500
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // Assert FIFO: both charges completed.
            $completedStatus = ChargeStatus::findOrFail(ChargeStatus::COMPLETED);
            foreach ($chargeIds as $chargeId) {
                $charge = Charge::find($chargeId);
                $this->assertSame($completedStatus->id, $charge->charge_status_id,
                    "Charge {$chargeId} must be completed by FIFO");
                $this->assertSame(70000, (int) $charge->getAttributes()['paid_amount']);
            }

            // Assert credit_balance = 10000 (= $100 overpayment).
            $credit = CreditBalance::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(10000, (int) $credit->getAttributes()['amount'],
                'Credit balance must be 10000 (minor units) for $100 overpayment');

            // Create a new charge of $700 for this unit (concept identity + policy config).
            $concept3 = ChargeConcept::create([
                'code' => 'CHARGE_C3',
                'name' => 'Tercer cargo',
                'charge_concept_category_id' => $concept->concept->charge_concept_category_id,
                'is_active' => true,
            ]);

            $policy3 = ChargePolicy::create([
                'charge_concept_id' => $concept3->id,
                'name' => 'Política cargo 3',
                'amount_mode_id' => $concept->amount_mode_id,
                'fixed_amount' => 70000,
                'periodicity_id' => $concept->periodicity_id,
                'is_active' => true,
                'grace_days' => 0,
            ]);

            $batch3 = ChargeBatch::create([
                'charge_policy_id' => $policy3->id,
                'title' => 'Cargo 3',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'issued_at' => '2026-02-01',
                'due_date' => '2026-02-15',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);
            $generationService->activateBatch($batch3);

            $charge3 = Charge::where('charge_batch_id', $batch3->id)
                ->where('unit_id', $unit->id)
                ->firstOrFail();

            // Now register a second payment — credit ($100) should be consumed first.
            $payment2 = $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-02-10',
                'total_amount' => 60000, // $600 — with $100 credit covers $700
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // After credit consumption: charge3 should be completed.
            $charge3->refresh();
            $this->assertSame($completedStatus->id, $charge3->charge_status_id,
                'Charge 3 must be completed after credit ($100) + payment ($600) = $700');

            // Credit should now be 0.
            $credit->refresh();
            $this->assertSame(0, (int) $credit->getAttributes()['amount'],
                'Credit balance must be 0 after consumption');
        });
    }

    // =========================================================================
    // SPEC FLOW D: Single-unit fine (ad-hoc charge, others unaffected)
    // =========================================================================

    public function test_flow_d_single_unit_fine_does_not_affect_other_units(): void
    {
        $tenant = $this->makeTenant('Flow D Tenant');
        $this->seedTenant($tenant);

        $targetUnit = $this->makeUnit($tenant);
        $otherUnit = $this->makeUnit($tenant);
        $concept = $this->makeEventualFixedConcept($tenant, 50000, 'MULTA_D1'); // $500

        $tenant->run(function () use ($concept, $targetUnit, $otherUnit): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);

            // Create batch targeting single unit only.
            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => 'Multa bloqueo paso - Casa 12',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $targetUnit->id],
                'issued_at' => '2026-03-10',
                'due_date' => '2026-03-25',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            $generationService->activateBatch($batch);

            // Assert exactly 1 charge created.
            $charges = Charge::where('charge_batch_id', $batch->id)->get();
            $this->assertCount(1, $charges, 'Single-unit batch must create exactly 1 charge');

            // Assert the charge is for the target unit.
            $this->assertSame($targetUnit->id, $charges->first()->unit_id);

            // Assert expected_amount = 50000 minor units.
            $this->assertSame(50000, (int) $charges->first()->getAttributes()['expected_amount']);

            // Assert other unit has NO charge from this batch.
            $otherUnitCharge = Charge::where('charge_batch_id', $batch->id)
                ->where('unit_id', $otherUnit->id)
                ->first();
            $this->assertNull($otherUnitCharge, 'Other unit must not be affected by single-unit batch');
        });
    }

    // =========================================================================
    // ChargeStatusService: all 6 states
    // =========================================================================

    public function test_charge_status_service_all_six_states(): void
    {
        $tenant = $this->makeTenant('Status Test Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'STATUS_TEST_C');

        $tenant->run(function () use ($unit, $concept): void {
            $statusService = app(ChargeStatusService::class);
            $pendingStatus = ChargeStatus::findOrFail(ChargeStatus::PENDING);

            // Helper to make a charge with specific state.
            $makeCharge = function (array $attrs) use ($concept, $unit, $pendingStatus): Charge {
                return Charge::create(array_merge([
                    'charge_concept_id' => $concept->charge_concept_id,
                    'charge_policy_id' => $concept->id,
                    'unit_id' => $unit->id,
                    'expected_amount' => 70000,
                    'paid_amount' => 0,
                    'issued_at' => '2026-01-01',
                    'due_date' => '2026-12-31',
                    'grace_days' => 0,
                    'charge_status_id' => $pendingStatus->id,
                    'is_cancelled' => false,
                ], $attrs));
            };

            // 1. PENDING: no payment, not yet near due.
            $pending = $makeCharge(['due_date' => '2099-12-31']);
            $key = $statusService->resolveStatusKey($pending, 0);
            $this->assertSame(ChargeStatus::PENDING, $key, 'Far future due_date, no payment → pending');

            // 2. DUE_SOON: within 7 days of due, no payment.
            $tomorrow = now()->addDays(3)->format('Y-m-d');
            $dueSoon = $makeCharge(['due_date' => $tomorrow]);
            $key = $statusService->resolveStatusKey($dueSoon, 0);
            $this->assertSame(ChargeStatus::DUE_SOON, $key, 'Within 7 days of due, no payment → due_soon');

            // 3. OVERDUE: past due + grace.
            $overdue = $makeCharge(['due_date' => '2020-01-01', 'grace_days' => 5]);
            $key = $statusService->resolveStatusKey($overdue, 0);
            $this->assertSame(ChargeStatus::OVERDUE, $key, 'Past due + grace → overdue');

            // 4. PARTIAL: some payment, not complete, still within term.
            $partial = $makeCharge([
                'due_date' => '2099-12-31',
                'paid_amount' => 35000,
                'expected_amount' => 70000,
            ]);
            $key = $statusService->resolveStatusKey($partial, 0);
            $this->assertSame(ChargeStatus::PARTIAL, $key, 'Partial payment within term → partial');

            // 5. COMPLETED: fully paid.
            $completed = $makeCharge(['paid_amount' => 70000, 'expected_amount' => 70000, 'due_date' => '2026-01-15']);
            $key = $statusService->resolveStatusKey($completed, 0);
            $this->assertSame(ChargeStatus::COMPLETED, $key, 'Full payment → completed');

            // 6. OVERPAID: the CHARGE ITSELF is overpaid beyond the tolerance.
            // (Overpaid is per-charge; unrelated unit-level credit must NOT flip an
            //  exactly-paid charge — see test_exactly_paid_charge_with_unit_credit_is_completed.)
            $overpaid = $makeCharge(['paid_amount' => 80000, 'expected_amount' => 70000, 'due_date' => '2026-01-15']);
            $key = $statusService->resolveStatusKey($overpaid, 0);
            $this->assertSame(ChargeStatus::OVERPAID, $key, 'Charge paid beyond tolerance → overpaid');
        });
    }

    public function test_grace_days_prevent_premature_overdue(): void
    {
        $tenant = $this->makeTenant('Grace Test Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'GRACE_TEST_C');

        $tenant->run(function () use ($unit, $concept): void {
            $statusService = app(ChargeStatusService::class);
            $pendingStatus = ChargeStatus::findOrFail(ChargeStatus::PENDING);

            // Due yesterday, 5 grace days → should NOT be overdue yet.
            $yesterday = now()->subDay()->format('Y-m-d');
            $charge = Charge::create([
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'paid_amount' => 0,
                'issued_at' => '2026-01-01',
                'due_date' => $yesterday,
                'grace_days' => 5, // 5-day grace means still OK
                'charge_status_id' => $pendingStatus->id,
                'is_cancelled' => false,
            ]);

            $key = $statusService->resolveStatusKey($charge, 0);
            $this->assertNotSame(ChargeStatus::OVERDUE, $key,
                '1 day past due with 5-day grace should NOT be overdue');
        });
    }

    // =========================================================================
    // FIFO ordering test
    // =========================================================================

    public function test_fifo_applies_to_oldest_due_date_first(): void
    {
        $tenant = $this->makeTenant('FIFO Test Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'FIFO_CONCEPT');

        $tenant->run(function () use ($unit, $bankAccount, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            // Create 3 charges with different due dates (newest → oldest in creation order).
            $dueDates = ['2026-03-15', '2026-01-15', '2026-02-15'];
            $chargesByDue = [];

            foreach ($dueDates as $dueDate) {
                $batch = ChargeBatch::create([
                    'charge_policy_id' => $concept->id,
                    'title' => "Batch {$dueDate}",
                    'year' => 2026,
                    'month' => null,
                    'target_type' => 'single_unit',
                    'target_selector' => ['unit_id' => $unit->id],
                    'issued_at' => '2026-01-01',
                    'due_date' => $dueDate,
                    'charge_batch_status_id' => $draftStatus->id,
                    'progress_pct' => 0,
                ]);

                $generationService->activateBatch($batch);
                $charge = Charge::where('charge_batch_id', $batch->id)->firstOrFail();
                $chargesByDue[$dueDate] = $charge->id;
            }

            // Pay $70,000 — should go to the OLDEST due_date (2026-01-15).
            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-20',
                'total_amount' => 70000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $completedStatus = ChargeStatus::findOrFail(ChargeStatus::COMPLETED);

            // Oldest (Jan 15) must be completed.
            $oldestCharge = Charge::find($chargesByDue['2026-01-15']);
            $this->assertSame($completedStatus->id, $oldestCharge->charge_status_id,
                'FIFO must fill oldest due_date first');

            // Others must still be open.
            $febCharge = Charge::find($chargesByDue['2026-02-15']);
            $marCharge = Charge::find($chargesByDue['2026-03-15']);
            $this->assertNotSame($completedStatus->id, $febCharge->charge_status_id,
                'Feb charge must remain open');
            $this->assertNotSame($completedStatus->id, $marCharge->charge_status_id,
                'Mar charge must remain open');
        });
    }

    // =========================================================================
    // Payment cancel reverses applications + restores credit + decrements bank
    // =========================================================================

    public function test_payment_cancel_reverses_all_and_decrements_bank_balance(): void
    {
        $tenant = $this->makeTenant('Cancel Test Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'CANCEL_CONCEPT');

        $tenant->run(function () use ($unit, $bankAccount, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => 'Cancel batch',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'issued_at' => '2026-01-01',
                'due_date' => '2099-12-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            $generationService->activateBatch($batch);
            $charge = Charge::where('charge_batch_id', $batch->id)->firstOrFail();

            // Register payment.
            $payment = $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-10',
                'total_amount' => 80000, // $800 — $100 overpay → credit
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // Verify charge is completed, bank balance = 80000, credit = 10000.
            $charge->refresh();
            $completedStatus = ChargeStatus::findOrFail(ChargeStatus::COMPLETED);
            $this->assertSame($completedStatus->id, $charge->charge_status_id);
            $this->assertSame(80000, (int) $bankAccount->refresh()->getAttributes()['current_balance']);

            $credit = CreditBalance::where('unit_id', $unit->id)->first();
            $this->assertSame(10000, (int) $credit->getAttributes()['amount']);

            // Cancel the payment.
            $paymentService->cancel($payment, 'Error de captura, se cancela.');

            // Assert payment status = cancelled.
            $payment->refresh();
            $cancelledStatus = PaymentStatus::findOrFail(PaymentStatus::CANCELLED);
            $this->assertSame($cancelledStatus->id, $payment->payment_status_id);

            // Assert payment_application rows deleted/reversed.
            $apps = PaymentApplication::where('payment_id', $payment->id)->get();
            $this->assertCount(0, $apps, 'All applications must be reversed on payment cancel');

            // Assert charge.paid_amount = 0.
            $charge->refresh();
            $this->assertSame(0, (int) $charge->getAttributes()['paid_amount']);

            // Assert bank balance decremented back to 0.
            $this->assertSame(0, (int) $bankAccount->refresh()->getAttributes()['current_balance']);

            // Assert credit removed.
            $credit->refresh();
            $this->assertSame(0, (int) $credit->getAttributes()['amount']);
        });
    }

    // =========================================================================
    // Bank account balance: initial + confirmed payments
    // =========================================================================

    public function test_bank_account_balance_increments_on_confirmed_payment(): void
    {
        $tenant = $this->makeTenant('Bank Balance Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $initialBalance = 100000; // $1,000 initial
        $bankAccount = $this->makeBankAccount($tenant, $initialBalance);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'BANK_CONCEPT');

        $tenant->run(function () use ($unit, $bankAccount, $concept, $initialBalance): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => 'Bank balance test',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'issued_at' => '2026-01-01',
                'due_date' => '2099-12-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);
            $generationService->activateBatch($batch);

            // Register 2 payments.
            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-10',
                'total_amount' => 35000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-15',
                'total_amount' => 35000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $bankAccount->refresh();
            $this->assertSame(
                $initialBalance + 70000,
                (int) $bankAccount->getAttributes()['current_balance'],
                'Bank balance must equal initial + sum of confirmed payments'
            );
        });
    }

    // =========================================================================
    // Charge cancel requires reason + keeps history
    // =========================================================================

    public function test_charge_cancel_requires_reason_and_keeps_history(): void
    {
        $tenant = $this->makeTenant('Charge Cancel Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $concept = $this->makeEventualFixedConcept($tenant, 50000, 'CANCEL_CHG');

        $tenant->run(function () use ($unit, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => 'Cancel test',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'issued_at' => '2026-01-01',
                'due_date' => '2026-12-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            $generationService->activateBatch($batch);
            $charge = Charge::where('charge_batch_id', $batch->id)->firstOrFail();

            // Cancel the charge.
            $charge->update([
                'is_cancelled' => true,
                'cancellation_reason' => 'Emitido por error',
            ]);

            $charge->refresh();
            $this->assertTrue($charge->is_cancelled, 'Charge must be marked cancelled');
            $this->assertSame('Emitido por error', $charge->cancellation_reason,
                'Cancellation reason must be persisted');

            // Soft-delete aware: charge still queryable.
            $this->assertNotNull(Charge::withTrashed()->find($charge->id));
        });
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_tenant_isolation_charges_are_scoped_per_tenant(): void
    {
        $tenant1 = $this->makeTenant('Tenant 1');
        $tenant2 = $this->makeTenant('Tenant 2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $concept1 = $this->makeEventualFixedConcept($tenant1, 70000, 'ISOLATION_1');
        $unit1 = $this->makeUnit($tenant1);

        $tenant1->run(function () use ($concept1, $unit1): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept1->id,
                'title' => 'Isolated charge',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit1->id],
                'issued_at' => '2026-01-01',
                'due_date' => '2026-12-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            $generationService->activateBatch($batch);
        });

        // Tenant 2 must see 0 charges.
        $tenant2->run(function (): void {
            $this->assertSame(0, Charge::count(), 'Tenant 2 must see 0 charges from Tenant 1');
        });
    }

    // =========================================================================
    // Unique receipt_number per tenant
    // =========================================================================

    public function test_receipt_number_is_unique_per_tenant_and_sequential(): void
    {
        $tenant = $this->makeTenant('Receipt Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'RECEIPT_CONCEPT');

        $tenant->run(function () use ($unit, $bankAccount, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            for ($i = 0; $i < 3; $i++) {
                $batch = ChargeBatch::create([
                    'charge_policy_id' => $concept->id,
                    'title' => "Receipt test {$i}",
                    'year' => 2026,
                    'month' => null,
                    'target_type' => 'single_unit',
                    'target_selector' => ['unit_id' => $unit->id],
                    'issued_at' => '2026-01-01',
                    'due_date' => '2099-12-31',
                    'charge_batch_status_id' => $draftStatus->id,
                    'progress_pct' => 0,
                ]);
                $generationService->activateBatch($batch);
            }

            $receipts = [];
            for ($i = 0; $i < 3; $i++) {
                $payment = $paymentService->register([
                    'unit_id' => $unit->id,
                    'paid_at' => '2026-01-10',
                    'total_amount' => 10000,
                    'payment_method_id' => $paymentMethod->id,
                    'bank_account_id' => $bankAccount->id,
                ]);
                $receipts[] = $payment->receipt_number;
            }

            // All receipt numbers must be unique.
            $this->assertCount(3, array_unique($receipts), 'Receipt numbers must be unique');

            // Each must match R-YYYY-NNNNNN format.
            foreach ($receipts as $receipt) {
                $this->assertMatchesRegularExpression('/^R-2026-\d{6}$/', $receipt);
            }
        });
    }

    // =========================================================================
    // Money round-trip: assert integer storage
    // =========================================================================

    public function test_money_stored_as_integer_minor_units(): void
    {
        $tenant = $this->makeTenant('Money Test Tenant');
        $this->seedTenant($tenant);
        $concept = $this->makeMonthlyFixedConcept($tenant, 70050); // $700.50

        $tenant->run(function () use ($concept): void {
            // The raw DB value must be the integer we provided.
            $rawFixed = (int) $concept->getAttributes()['fixed_amount'];
            $this->assertSame(70050, $rawFixed,
                'fixed_amount must be stored as integer minor units, not decimal');
            $this->assertIsInt($rawFixed, 'fixed_amount raw DB value must be PHP int');
        });
    }

    // =========================================================================
    // AmountCalculator: by_share rounding
    // =========================================================================

    public function test_amount_calculator_by_share_rounds_correctly(): void
    {
        $tenant = $this->makeTenant('Calculator Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant, 1.3542); // 1.3542% share

        $tenant->run(function () use ($unit): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

            $concept = ChargeConcept::create([
                'code' => 'BY_SHARE_TEST',
                'name' => 'By share test',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'By share policy',
                'amount_mode_id' => AmountMode::BY_SHARE,
                'prorate_total_amount' => 1000000, // $10,000 total to prorate
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'is_active' => true,
            ]);

            $calculator = app(AmountCalculator::class);
            $amount = $calculator->compute($policy, $unit);

            // Expected: round(1000000 * (1.3542 / 100)) = round(13542) = 13542
            $this->assertSame(13542, $amount,
                'AmountCalculator must correctly compute by_share amount');
            $this->assertIsInt($amount, 'Result must be integer minor units');
        });
    }

    // =========================================================================
    // Catalog seeding: all tenant catalogs present after seeding
    // =========================================================================

    public function test_fresh_tenant_has_all_collections_catalogs_after_seeding(): void
    {
        $tenant = $this->makeTenant('Catalog Test Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            // ChargeStatus: 7 keys.
            $this->assertSame(7, ChargeStatus::count(), 'Must have 7 charge statuses');

            // PaymentStatus: 3 keys.
            $this->assertSame(3, PaymentStatus::count(), 'Must have 3 payment statuses');

            // ChargeBatchStatus: 3 keys.
            $this->assertSame(3, ChargeBatchStatus::count(), 'Must have 3 batch statuses');

            // ChargeConceptCategory: 5 keys.
            $this->assertSame(5, ChargeConceptCategory::count(), 'Must have 5 concept categories');

            // AmountMode: 5 keys.
            $this->assertSame(5, AmountMode::count(), 'Must have 5 amount modes');

            // Periodicity: 4 keys (MONTHLY, YEARLY, EVENTUAL + EVERY_TWO_DAYS, the compressed
            // QA cadence). BIMONTHLY/WEEKLY/BIWEEKLY are deliberately NOT seeded — see
            // CatalogSeeder::seedPeriodicities() and CatalogSeederPeriodicityTest.
            $this->assertSame(4, Periodicity::count(), 'Must have 4 periodicities');

            // PaymentMethod: 4 keys.
            $this->assertSame(4, PaymentMethod::count(), 'Must have 4 payment methods');
        });
    }

    // =========================================================================
    // paid_amount equals sum of applications (data integrity)
    // =========================================================================

    public function test_paid_amount_equals_sum_of_applications(): void
    {
        $tenant = $this->makeTenant('Integrity Test Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'INTEGRITY_C');

        $tenant->run(function () use ($unit, $bankAccount, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => 'Integrity test',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'issued_at' => '2026-01-01',
                'due_date' => '2099-12-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            $generationService->activateBatch($batch);
            $charge = Charge::where('charge_batch_id', $batch->id)->firstOrFail();

            // Two partial payments.
            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => 30000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-10',
                'total_amount' => 20000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $charge->refresh();
            $sumApplications = PaymentApplication::where('charge_id', $charge->id)
                ->sum('applied_amount');

            $this->assertSame(
                (int) $charge->getAttributes()['paid_amount'],
                (int) $sumApplications,
                'paid_amount must equal sum of payment_application.applied_amount'
            );
        });
    }

    // =========================================================================
    // FIX 1 — cancel() must restore CONSUMED credit and uphold the ledger invariant:
    //   Σ(charge.paid_amount) + credit_balance.amount == Σ(confirmed payment.total_amount)
    // =========================================================================

    /**
     * Helper: assert the ledger invariant for a unit (must be called inside tenant context).
     */
    private function assertLedgerInvariant(string $unitId, string $context): void
    {
        $sumPaid = (int) Charge::where('unit_id', $unitId)->sum('paid_amount');

        $credit = CreditBalance::where('unit_id', $unitId)->first();
        $creditRaw = $credit ? (int) $credit->getAttributes()['amount'] : 0;
        $this->assertGreaterThanOrEqual(0, $creditRaw, "credit must not be negative ({$context})");

        $confirmedKeyId = PaymentStatus::CONFIRMED;
        $sumConfirmed = (int) Payment::where('unit_id', $unitId)
            ->where('payment_status_id', $confirmedKeyId)
            ->sum('total_amount');

        foreach (Charge::where('unit_id', $unitId)->get() as $c) {
            $this->assertGreaterThanOrEqual(0, (int) $c->getAttributes()['paid_amount'],
                "charge paid_amount must not be negative ({$context})");
        }

        $this->assertSame(
            $sumConfirmed,
            $sumPaid + $creditRaw,
            "LEDGER INVARIANT ({$context}): Σpaid({$sumPaid}) + credit({$creditRaw}) must equal Σconfirmed({$sumConfirmed})"
        );
    }

    public function test_cancel_payment_that_consumed_credit_restores_credit_and_holds_invariant(): void
    {
        $tenant = $this->makeTenant('Cancel-Credit B Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'CC_CONSUME');

        $tenant->run(function () use ($unit, $bankAccount, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $makeCharge = function (string $code, string $due) use ($concept, $unit, $draftStatus, $generationService): Charge {
                $batch = ChargeBatch::create([
                    'charge_policy_id' => $concept->id,
                    'title' => $code,
                    'year' => 2026,
                    'month' => null,
                    'target_type' => 'single_unit',
                    'target_selector' => ['unit_id' => $unit->id],
                    'issued_at' => '2026-01-01',
                    'due_date' => $due,
                    'charge_batch_status_id' => $draftStatus->id,
                    'progress_pct' => 0,
                ]);
                $generationService->activateBatch($batch);

                return Charge::where('charge_batch_id', $batch->id)->firstOrFail();
            };

            // Charge X=70000; Pay A=80000 → X paid, credit=10000.
            $chargeX = $makeCharge('X', '2099-01-15');
            $paymentA = $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => 80000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $this->assertSame(10000, (int) CreditBalance::where('unit_id', $unit->id)->firstOrFail()->getAttributes()['amount']);
            $this->assertLedgerInvariant($unit->id, 'after A');

            // Charge Y=70000 → Flujo C.5 (§8.7.3.4): activating Y auto-applies the
            // 10000 credit immediately. Y starts partial (10000), credit=0.
            $chargeY = $makeCharge('Y', '2099-02-15');

            $chargeY->refresh();
            $this->assertSame(10000, (int) $chargeY->getAttributes()['paid_amount'], 'Y auto-consumed the credit on activation');
            $this->assertSame(0, (int) CreditBalance::where('unit_id', $unit->id)->firstOrFail()->getAttributes()['amount']);

            // Pay B=60000 cash → completes Y (10000 credit + 60000 cash).
            $paymentB = $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-02-05',
                'total_amount' => 60000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $chargeY->refresh();
            $this->assertSame(70000, (int) $chargeY->getAttributes()['paid_amount'], 'Y fully paid via credit+cash');
            $this->assertSame(0, (int) CreditBalance::where('unit_id', $unit->id)->firstOrFail()->getAttributes()['amount']);
            $this->assertLedgerInvariant($unit->id, 'after B');

            // Cancel B → only B's cash (60000) is reversed. The 10000 credit was
            // consumed by Y's activation (funded by A, which stays confirmed), so
            // it remains applied: Y returns to partial, no credit is restored.
            $paymentService->cancel($paymentB, 'Reverso de prueba.');

            $chargeY->refresh();
            $this->assertSame(10000, (int) $chargeY->getAttributes()['paid_amount'], 'Y keeps the auto-applied credit after cancelling B');
            $this->assertSame(
                0,
                (int) CreditBalance::where('unit_id', $unit->id)->firstOrFail()->getAttributes()['amount'],
                'B consumed no credit, so none is restored on its cancel'
            );
            // Bank held A(80000)+B(60000)=140000; cancelling B reverses 60000 → 80000 remains (A still confirmed).
            $this->assertSame(80000, (int) $bankAccount->refresh()->getAttributes()['current_balance'], 'bank: 140000 − 60000 (B reversed) = 80000');
            $this->assertLedgerInvariant($unit->id, 'after cancel B');
        });
    }

    public function test_cancel_credit_generating_payment_after_credit_consumed_holds_invariant(): void
    {
        $tenant = $this->makeTenant('Cancel-Credit A Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'CC_GEN');

        $tenant->run(function () use ($unit, $bankAccount, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $makeCharge = function (string $code, string $due) use ($concept, $unit, $draftStatus, $generationService): Charge {
                $batch = ChargeBatch::create([
                    'charge_policy_id' => $concept->id,
                    'title' => $code,
                    'year' => 2026,
                    'month' => null,
                    'target_type' => 'single_unit',
                    'target_selector' => ['unit_id' => $unit->id],
                    'issued_at' => '2026-01-01',
                    'due_date' => $due,
                    'charge_batch_status_id' => $draftStatus->id,
                    'progress_pct' => 0,
                ]);
                $generationService->activateBatch($batch);

                return Charge::where('charge_batch_id', $batch->id)->firstOrFail();
            };

            // Same setup as the other test.
            $chargeX = $makeCharge('X', '2099-01-15');
            $paymentA = $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => 80000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $chargeY = $makeCharge('Y', '2099-02-15');
            $paymentB = $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-02-05',
                'total_amount' => 60000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);
            $this->assertLedgerInvariant($unit->id, 'after B');

            // Cancel A (the credit-generator) AFTER its credit was already consumed by B.
            // X must become unpaid; invariant must hold; no negative balances.
            $paymentService->cancel($paymentA, 'Reverso del pago que generó el crédito.');

            $chargeX->refresh();
            $this->assertSame(0, (int) $chargeX->getAttributes()['paid_amount'], 'X must be unpaid after cancelling A');
            $this->assertLedgerInvariant($unit->id, 'after cancel A');

            // After cancelling A, only B (60000) remains confirmed. The 10000 credit A generated
            // and B consumed is clawed back from Y, leaving Y at 60000 paid.
            $chargeY->refresh();
            $this->assertSame(60000, (int) $chargeY->getAttributes()['paid_amount'],
                'Clawback: Y drops to the cash B actually covered (60000)');
        });
    }

    // =========================================================================
    // FIX 2 — ChargeStatusService reads per-tenant thresholds from Setting
    // =========================================================================

    public function test_due_soon_days_threshold_read_from_settings(): void
    {
        $tenant = $this->makeTenant('Settings Threshold Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'THRESH_C');

        $tenant->run(function () use ($unit, $concept): void {
            $statusService = app(ChargeStatusService::class);
            $pendingStatus = ChargeStatus::findOrFail(ChargeStatus::PENDING);

            // A charge due 10 days out, no payment.
            $tenDaysOut = now()->addDays(10)->format('Y-m-d');
            $charge = Charge::create([
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'paid_amount' => 0,
                'issued_at' => '2026-01-01',
                'due_date' => $tenDaysOut,
                'grace_days' => 0,
                'charge_status_id' => $pendingStatus->id,
                'is_cancelled' => false,
            ]);

            // With the default (7 days), 10 days out is still pending.
            $this->assertSame(
                ChargeStatus::PENDING,
                $statusService->resolveStatusKey($charge, 0),
                'With default due_soon_days=7, a charge 10 days out is pending'
            );

            // Configure due_soon_days=14 for this tenant → now 10 days out is due_soon.
            // updateOrCreate keeps this idempotent if a prior committed row leaked across
            // the non-transactional suite boundary (the suite disables transactions).
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días para próximo a vencer', 'value' => 14, 'type' => Setting::TYPE_INTEGER],
            );
            Setting::updateOrCreate(
                ['slug' => 'collections.overpayment_pct'],
                ['name' => 'Porcentaje de sobrepago', 'value' => '2.0', 'type' => Setting::TYPE_STRING],
            );

            $this->assertSame(
                ChargeStatus::DUE_SOON,
                $statusService->resolveStatusKey($charge, 0),
                'With due_soon_days=14 from Setting, a charge 10 days out is due_soon'
            );

            // Sanity: getValue resolves the per-tenant values with correct types.
            $this->assertSame(14, (int) Setting::getValue('collections.due_soon_days'));
            $this->assertSame(2.0, (float) Setting::getValue('collections.overpayment_pct'));
        });
    }

    // =========================================================================
    // FIX 3 — exactly-paid charge with unrelated unit credit is COMPLETED (not overpaid),
    //         and a fully-paid batch shows progress_pct=100 even when the unit carries credit.
    // =========================================================================

    public function test_exactly_paid_charge_with_unit_credit_is_completed(): void
    {
        $tenant = $this->makeTenant('Exact-Paid Credit Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'EXACT_C');

        $tenant->run(function () use ($unit, $concept): void {
            $statusService = app(ChargeStatusService::class);
            $pendingStatus = ChargeStatus::findOrFail(ChargeStatus::PENDING);

            $charge = Charge::create([
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'paid_amount' => 70000, // exactly paid
                'issued_at' => '2026-01-01',
                'due_date' => '2026-01-15',
                'grace_days' => 0,
                'charge_status_id' => $pendingStatus->id,
                'is_cancelled' => false,
            ]);

            // Unrelated unit-level credit of 5000 must NOT flip the exactly-paid charge.
            $this->assertSame(
                ChargeStatus::COMPLETED,
                $statusService->resolveStatusKey($charge, 5000),
                'Exactly-paid charge + unrelated unit credit must be completed, NOT overpaid'
            );
        });
    }

    public function test_batch_progress_is_100_when_all_charges_paid_even_with_unit_credit(): void
    {
        $tenant = $this->makeTenant('Batch 100 Credit Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 0);
        $concept = $this->makeEventualFixedConcept($tenant, 70000, 'PROGRESS_C');

        $tenant->run(function () use ($unit, $bankAccount, $concept): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => 'Progress batch',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'issued_at' => '2026-01-01',
                'due_date' => '2099-12-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);
            $generationService->activateBatch($batch);

            // Give the unit unrelated standalone credit first.
            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-02',
                'total_amount' => 5000, // no open charge yet for this? there is one — applies to it
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // Now pay the rest so the charge is exactly covered (5000 already applied + 65000).
            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-03',
                'total_amount' => 65000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // Add genuine surplus credit unrelated to the charge.
            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-04',
                'total_amount' => 8000, // no open charge remains → becomes credit
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $charge = Charge::where('charge_batch_id', $batch->id)->firstOrFail();
            $completedStatusId = ChargeStatus::COMPLETED;
            $this->assertSame($completedStatusId, $charge->charge_status_id, 'Exactly-paid charge must be completed');

            $creditRaw = (int) CreditBalance::where('unit_id', $unit->id)->firstOrFail()->getAttributes()['amount'];
            $this->assertSame(8000, $creditRaw, 'Unit carries surplus credit');

            $batch->refresh();
            $this->assertSame(100, (int) $batch->progress_pct,
                'Fully collected batch must show 100% even when the unit carries credit');
        });
    }

    // =========================================================================
    // FIX 6 — by_share largest-remainder allocation sums EXACTLY to prorate_total
    // =========================================================================

    public function test_by_share_allocation_sums_exactly_to_prorate_total(): void
    {
        $tenant = $this->makeTenant('Largest Remainder Tenant');
        $this->seedTenant($tenant);

        // 3 units whose shares sum to 100% but produce fractional centavos when prorated.
        $unitA = $this->makeUnit($tenant, 33.3333);
        $unitB = $this->makeUnit($tenant, 33.3333);
        $unitC = $this->makeUnit($tenant, 33.3334);

        $tenant->run(function () use ($unitA, $unitB, $unitC): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

            $prorateTotal = 100001; // odd total → forces a leftover centavo
            $concept = ChargeConcept::create([
                'code' => 'LR_TEST',
                'name' => 'Largest remainder',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Largest remainder policy',
                'amount_mode_id' => AmountMode::BY_SHARE,
                'prorate_total_amount' => $prorateTotal,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2026-01-01',
                'is_active' => true,
            ]);

            $units = Unit::whereIn('id', [$unitA->id, $unitB->id, $unitC->id])->get();

            $calculator = app(AmountCalculator::class);
            $allocation = $calculator->computeByShareAllocation($policy, $units);

            $this->assertCount(3, $allocation);
            foreach ($allocation as $amount) {
                $this->assertIsInt($amount, 'Each allocation must be integer minor units');
            }

            $this->assertSame(
                $prorateTotal,
                array_sum($allocation),
                'Largest-remainder allocation must sum EXACTLY to prorate_total_amount (zero drift)'
            );
        });
    }

    // =========================================================================
    // FIX 5 — monthly cron activates a stuck DRAFT batch instead of skipping it
    // =========================================================================

    public function test_monthly_cron_activates_existing_draft_batch(): void
    {
        // Pin the date to the tenant's default generation day (1) so the daily,
        // self-filtering cron actually runs for this tenant.
        Carbon::setTestNow(Carbon::create(2026, 3, 1, 6, 0, 0));

        $tenant = $this->makeTenant('Cron Draft Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $today = now();

        // Create the monthly concept for the CURRENT period. valid_from = today: a virgin
        // policy with no backlog, so the chain's first (and only) attempt IS the collision
        // with the stuck draft below — isolates this test to the recovery path itself.
        $concept = $this->makeMonthlyFixedConcept($tenant, 70000, '2026-03-01');

        $tenant->run(function () use ($concept, $today): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

            // Simulate a leftover DRAFT batch for this concept/period (activate previously
            // threw). ANCHORED BILLING PERIODS: recovery now matches by period_start (the
            // idempotency key), not year/month/cycle, so the fixture must carry the SAME
            // period_start the chain will compute for this period (valid_from=2026-01-01,
            // chained monthly → the March period starts exactly 2026-03-01 = $today).
            $stuck = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => "{$concept->name} stuck draft",
                'year' => $today->year,
                'month' => $today->month,
                'period_start' => $today->toDateString(),
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => $today->toDateString(),
                'due_date' => $today->copy()->addDays(15)->toDateString(),
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            // No charges yet.
            $this->assertSame(0, Charge::where('charge_batch_id', $stuck->id)->count());
        });

        // Run the cron — it must hit the unique violation, find the draft, and activate it.
        Artisan::call('collections:generate-monthly');

        $tenant->run(function () use ($concept, $today, $unit): void {
            $activeStatusId = ChargeBatchStatus::ACTIVE;

            $batches = ChargeBatch::where('charge_policy_id', $concept->id)
                ->where('year', $today->year)
                ->where('month', $today->month)
                ->get();

            // Still exactly ONE batch (no duplicate created).
            $this->assertCount(1, $batches, 'Cron must not duplicate the period batch');
            $this->assertSame($activeStatusId, $batches->first()->charge_batch_status_id,
                'The stuck DRAFT batch must be ACTIVE after the cron runs');

            // And it generated the unit charge.
            $this->assertSame(
                1,
                Charge::where('charge_batch_id', $batches->first()->id)->where('unit_id', $unit->id)->count(),
                'Cron must generate the charge for the previously-stuck draft batch'
            );
        });
    }

    // =========================================================================
    // FIX A2 — inactive units receive NO new charges (spec §6.5 r3)
    // =========================================================================

    public function test_inactive_unit_does_not_receive_a_charge_but_billable_ones_do(): void
    {
        $tenant = $this->makeTenant('Inactive Unit Tenant');
        $this->seedTenant($tenant);

        // One unit per billable status (active/vacant/under_construction) + one inactive.
        $active = $this->makeUnitWithStatus($tenant, Catalog::id(UnitStatus::class, 'Activa'));
        $vacant = $this->makeUnitWithStatus($tenant, Catalog::id(UnitStatus::class, 'Vacante'));
        $underConstruction = $this->makeUnitWithStatus($tenant, Catalog::id(UnitStatus::class, 'En obra'));
        $inactive = $this->makeUnitWithStatus($tenant, Catalog::id(UnitStatus::class, 'Inactiva'));

        $concept = $this->makeMonthlyFixedConcept($tenant, 70000);

        $tenant->run(function () use ($concept, $active, $vacant, $underConstruction, $inactive): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $concept->id,
                'title' => 'Mantenimiento con inactiva',
                'year' => 2026,
                'month' => 4,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2026-04-01',
                'due_date' => '2026-04-15',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            app(ChargeGenerationService::class)->activateBatch($batch->refresh());

            // 3 billable units get a charge; the inactive one does NOT.
            $this->assertSame(3, Charge::where('charge_batch_id', $batch->id)->count());
            $this->assertSame(1, Charge::where('charge_batch_id', $batch->id)->where('unit_id', $active->id)->count());
            $this->assertSame(1, Charge::where('charge_batch_id', $batch->id)->where('unit_id', $vacant->id)->count(),
                'Vacant unit is still billable');
            $this->assertSame(1, Charge::where('charge_batch_id', $batch->id)->where('unit_id', $underConstruction->id)->count(),
                'Under-construction unit is still billable');
            $this->assertSame(0, Charge::where('charge_batch_id', $batch->id)->where('unit_id', $inactive->id)->count(),
                'INACTIVE unit must NOT receive a new charge (spec §6.5 r3)');
        });
    }

    public function test_target_resolver_skips_inactive_unit_even_when_explicitly_targeted(): void
    {
        $tenant = $this->makeTenant('Manual Inactive Tenant');
        $this->seedTenant($tenant);

        $active = $this->makeUnitWithStatus($tenant, Catalog::id(UnitStatus::class, 'Activa'));
        $inactive = $this->makeUnitWithStatus($tenant, Catalog::id(UnitStatus::class, 'Inactiva'));

        $tenant->run(function () use ($active, $inactive): void {
            $resolver = app(TargetResolver::class);

            // manual selector includes BOTH; the inactive one is skipped silently.
            $manual = $resolver->resolve('manual', ['unit_ids' => [$active->id, $inactive->id]]);
            $this->assertSame([$active->id], $manual->pluck('id')->all());

            // single_unit targeting an inactive unit resolves to an empty set.
            $single = $resolver->resolve('single_unit', ['unit_id' => $inactive->id]);
            $this->assertCount(0, $single, 'Explicitly targeting an inactive unit yields no charge');
        });
    }

    private function makeUnitWithStatus(Tenant $tenant, string $statusId): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit, $statusId): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(100, 99999),
                'unit_status_id' => $statusId,
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    // =========================================================================
    // FIX A3 — monthly cron honors per-tenant charge_generation_day
    // =========================================================================

    public function test_cron_respects_configurable_charge_generation_day(): void
    {
        $tenant = $this->makeTenant('Gen Day Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        // Configure this tenant to generate on day 15.
        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.charge_generation_day'],
                ['name' => 'Día de generación', 'value' => 15, 'type' => Setting::TYPE_INTEGER],
            );
        });

        // valid_from = the current period's own start (May) — with no backlog behind it,
        // the day-1 run has nothing to catch up on, isolating this test to what it actually
        // exercises: does day 1 vs day 15 gate THIS period's generation.
        $concept = $this->makeMonthlyFixedConcept($tenant, 70000, '2026-05-01');

        // Run on day 1 — the cron must NOT clone a period batch (tenant configured for
        // day 15). The off-test INITIAL template (is_initial=true) is ignored here: we
        // assert only on the cron's period CLONES (is_initial=false) and their charges.
        Carbon::setTestNow(Carbon::create(2026, 5, 1, 6, 0, 0));
        Artisan::call('collections:generate-monthly');

        $tenant->run(function (): void {
            $this->assertSame(0, ChargeBatch::where('is_initial', false)->count(), 'Day 1 must NOT generate for a day-15 tenant');
            $this->assertSame(0, Charge::count());
        });

        // Run on day 15 — the cron clones the template for the May period.
        Carbon::setTestNow(Carbon::create(2026, 5, 15, 6, 0, 0));
        Artisan::call('collections:generate-monthly');

        $tenant->run(function () use ($concept, $unit): void {
            $batches = ChargeBatch::where('charge_policy_id', $concept->id)
                ->where('is_initial', false)
                ->get();
            $this->assertCount(1, $batches, 'Day 15 must generate one batch for a day-15 tenant');
            $this->assertSame(
                1,
                Charge::where('charge_batch_id', $batches->first()->id)->where('unit_id', $unit->id)->count(),
                'Day 15 run must generate the unit charge'
            );
        });
    }
}
