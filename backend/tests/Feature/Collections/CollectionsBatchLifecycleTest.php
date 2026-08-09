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
 * ChargeBatch lifecycle tests.
 *
 * Covers:
 *  - activate DRAFT → creates charges, status → ACTIVE
 *  - activating an ACTIVE batch is idempotent (no duplicate charges)
 *  - activating a CANCELLED batch throws
 *  - cancel DRAFT → CANCELLED
 *  - cancel ACTIVE with no payments → cancels all charges
 *  - cancel ACTIVE where a charge has a payment application → throws
 *  - cancel already CANCELLED → throws
 *  - progress_pct = round(completed+overpaid / total * 100)
 */
final class CollectionsBatchLifecycleTest extends TestCase
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

    private function makeEventualFixedPolicy(Tenant $tenant, int $amount, string $code): ChargePolicy
    {
        $policy = null;
        $tenant->run(function () use (&$policy, $amount, $code): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => $code,
                'name' => 'Test concept',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Test policy',
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
     * Create a ChargeBatch (DRAFT) with 'all' target. Must be called INSIDE tenant->run().
     */
    private function makeDraftBatch(ChargePolicy $policy, string $title, string $targetType = 'all', ?array $selector = null): ChargeBatch
    {
        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

        return ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => $title,
            'year' => 2026,
            'month' => null,
            'target_type' => $targetType,
            'target_selector' => $selector,
            'issued_at' => '2026-01-01',
            'due_date' => '2099-12-31',
            'charge_batch_status_id' => $draftStatus->id,
            'progress_pct' => 0,
        ]);
    }

    // =========================================================================
    // Activate DRAFT → creates charges → ACTIVE
    // =========================================================================

    public function test_activate_draft_creates_charges_and_sets_active(): void
    {
        $tenant = $this->makeTenant('Activate Tenant');
        $this->seedTenant($tenant);

        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_ACT');

        $tenant->run(function () use ($policy): void {
            $batch = $this->makeDraftBatch($policy, 'Activate test');
            $activatedBatch = app(ChargeGenerationService::class)->activateBatch($batch);

            $activatedBatch->refresh();
            $this->assertSame(ChargeBatchStatus::ACTIVE, $activatedBatch->charge_batch_status_id, 'Batch must be ACTIVE after activation');

            $charges = Charge::where('charge_batch_id', $activatedBatch->id)->get();
            $this->assertCount(2, $charges, 'Must create one charge per billable unit');
        });
    }

    // =========================================================================
    // Activate already-ACTIVE batch is idempotent (no duplicate charges)
    // =========================================================================

    public function test_activate_already_active_batch_is_idempotent(): void
    {
        $tenant = $this->makeTenant('IdempotentActivate Tenant');
        $this->seedTenant($tenant);

        $this->makeUnit($tenant);
        $this->makeUnit($tenant);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_IDEM');

        $tenant->run(function () use ($policy): void {
            $batch = $this->makeDraftBatch($policy, 'Idempotent test');
            $generationService = app(ChargeGenerationService::class);

            $generationService->activateBatch($batch);
            $countAfterFirst = Charge::where('charge_batch_id', $batch->id)->count();

            // Activate again — must NOT add more charges
            $generationService->activateBatch($batch->refresh());
            $countAfterSecond = Charge::where('charge_batch_id', $batch->id)->count();

            $this->assertSame($countAfterFirst, $countAfterSecond, 'Re-activating an ACTIVE batch must not duplicate charges');
        });
    }

    // =========================================================================
    // Activate CANCELLED batch → throws
    // =========================================================================

    public function test_activate_cancelled_batch_throws(): void
    {
        $tenant = $this->makeTenant('ActivateCancelled Tenant');
        $this->seedTenant($tenant);

        $this->makeUnit($tenant);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_ACTC');

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function () use ($policy): void {
            $batch = $this->makeDraftBatch($policy, 'Cancel first then activate');
            $generationService = app(ChargeGenerationService::class);

            // Cancel batch while still DRAFT (no charges yet, no payments)
            $generationService->cancelBatch($batch, 'Cancelado para prueba');

            // Now attempt to activate → must throw
            $generationService->activateBatch($batch->refresh());
        });
    }

    // =========================================================================
    // Cancel DRAFT → CANCELLED
    // =========================================================================

    public function test_cancel_draft_batch_sets_cancelled_status(): void
    {
        $tenant = $this->makeTenant('CancelDraft Tenant');
        $this->seedTenant($tenant);

        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_CDRAFT');

        $tenant->run(function () use ($policy): void {
            $batch = $this->makeDraftBatch($policy, 'Cancel draft test');
            $cancelled = app(ChargeGenerationService::class)->cancelBatch($batch, 'Error al crear');

            $this->assertSame(ChargeBatchStatus::CANCELLED, $cancelled->charge_batch_status_id, 'Batch must be CANCELLED');
        });
    }

    // =========================================================================
    // Cancel ACTIVE with no payments → cancels all charges
    // =========================================================================

    public function test_cancel_active_batch_with_no_payments_cancels_all_charges(): void
    {
        $tenant = $this->makeTenant('CancelActive Tenant');
        $this->seedTenant($tenant);

        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_CACT');

        $tenant->run(function () use ($policy): void {
            $batch = $this->makeDraftBatch($policy, 'Cancel active test');
            $generationService = app(ChargeGenerationService::class);

            $generationService->activateBatch($batch);
            $charges = Charge::where('charge_batch_id', $batch->id)->get();
            $this->assertCount(2, $charges, 'Precondition: 2 charges exist');

            // Cancel the active batch
            $cancelled = $generationService->cancelBatch($batch->refresh(), 'Error de lote');

            $this->assertSame(ChargeBatchStatus::CANCELLED, $cancelled->charge_batch_status_id, 'Batch must be CANCELLED');

            // All charges must be marked is_cancelled = true
            $refreshedCharges = Charge::where('charge_batch_id', $batch->id)->get();
            foreach ($refreshedCharges as $charge) {
                $this->assertTrue($charge->is_cancelled, "Charge {$charge->id} must be cancelled");
            }
        });
    }

    // =========================================================================
    // Cancel ACTIVE with a payment application → throws (must cancel payments first)
    // =========================================================================

    public function test_cancel_active_batch_with_payment_application_throws(): void
    {
        $tenant = $this->makeTenant('CancelWithPayment Tenant');
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_CPAY');

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function () use ($policy, $unit, $bank): void {
            $batch = $this->makeDraftBatch($policy, 'Cancel with payment test', 'single_unit', ['unit_id' => $unit->id]);
            $generationService = app(ChargeGenerationService::class);

            $generationService->activateBatch($batch);
            $charge = Charge::where('charge_batch_id', $batch->id)->firstOrFail();

            // Register a payment → creates payment_application rows
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => 35000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);

            // Confirm the charge has an application
            $charge->refresh();
            $this->assertGreaterThan(0, $charge->payment_applications()->count(), 'Precondition: charge has payment applications');

            // Attempting to cancel the batch must throw
            $generationService->cancelBatch($batch->refresh(), 'Intento cancelación');
        });
    }

    // =========================================================================
    // Cancel already CANCELLED batch → throws
    // =========================================================================

    public function test_cancel_already_cancelled_batch_throws(): void
    {
        $tenant = $this->makeTenant('DoubleCancelBatch Tenant');
        $this->seedTenant($tenant);

        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_CDBL');

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function () use ($policy): void {
            $batch = $this->makeDraftBatch($policy, 'Double cancel batch test');
            $generationService = app(ChargeGenerationService::class);

            $generationService->cancelBatch($batch, 'Primera cancelación');

            // Second cancel → must throw
            $generationService->cancelBatch($batch->refresh(), 'Segunda cancelación');
        });
    }

    // =========================================================================
    // progress_pct = round((completed + overpaid) / total * 100)
    // =========================================================================

    public function test_progress_pct_computed_correctly_for_completed_and_overpaid_charges(): void
    {
        $tenant = $this->makeTenant('Progress Tenant');
        $this->seedTenant($tenant);

        // 4 units: we'll pay 2 fully + 1 overpaid → progress = round(3/4*100) = 75
        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);
        $unit3 = $this->makeUnit($tenant);
        $unit4 = $this->makeUnit($tenant);

        $bank = $this->makeBankAccount($tenant, 0);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_PROG');

        $tenant->run(function () use ($policy, $unit1, $unit2, $unit3, $bank): void {
            $batch = $this->makeDraftBatch($policy, 'Progress test');
            app(ChargeGenerationService::class)->activateBatch($batch);

            $charges = Charge::where('charge_batch_id', $batch->id)->get()->keyBy('unit_id');
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            $paymentService = app(PaymentService::class);

            // Pay unit1 fully (70000)
            $paymentService->register(['unit_id' => $unit1->id, 'paid_at' => '2026-01-05', 'total_amount' => 70000, 'payment_method_id' => $paymentMethod->id, 'bank_account_id' => $bank->id]);
            // Pay unit2 fully (70000)
            $paymentService->register(['unit_id' => $unit2->id, 'paid_at' => '2026-01-05', 'total_amount' => 70000, 'payment_method_id' => $paymentMethod->id, 'bank_account_id' => $bank->id]);
            // Pay unit3 more than overpayment threshold (70000 * 1.011 = ~70770 → 80000 definitely overpaid)
            $paymentService->register(['unit_id' => $unit3->id, 'paid_at' => '2026-01-05', 'total_amount' => 80000, 'payment_method_id' => $paymentMethod->id, 'bank_account_id' => $bank->id]);
            // unit4 stays unpaid (partial or pending)

            $batch->refresh();
            // 3 charges done (2 completed + 1 overpaid), 1 pending → 75%
            $this->assertSame(75, (int) $batch->progress_pct, 'progress_pct must be 75 (3/4 × 100) with 2 completed + 1 overpaid');
        });
    }

    public function test_progress_pct_is_100_when_all_charges_completed(): void
    {
        $tenant = $this->makeTenant('Progress100 Tenant');
        $this->seedTenant($tenant);

        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);

        $bank = $this->makeBankAccount($tenant, 0);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_P100');

        $tenant->run(function () use ($policy, $unit1, $unit2, $bank): void {
            $batch = $this->makeDraftBatch($policy, 'Progress 100 test');
            app(ChargeGenerationService::class)->activateBatch($batch);

            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            $paymentService = app(PaymentService::class);

            $paymentService->register(['unit_id' => $unit1->id, 'paid_at' => '2026-01-05', 'total_amount' => 70000, 'payment_method_id' => $paymentMethod->id, 'bank_account_id' => $bank->id]);
            $paymentService->register(['unit_id' => $unit2->id, 'paid_at' => '2026-01-05', 'total_amount' => 70000, 'payment_method_id' => $paymentMethod->id, 'bank_account_id' => $bank->id]);

            $batch->refresh();
            $this->assertSame(100, (int) $batch->progress_pct, 'All charges completed → 100%');
        });
    }

    public function test_progress_pct_is_0_when_no_charges_completed(): void
    {
        $tenant = $this->makeTenant('Progress0 Tenant');
        $this->seedTenant($tenant);

        $this->makeUnit($tenant);
        $this->makeUnit($tenant);

        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_P0');

        $tenant->run(function () use ($policy): void {
            $batch = $this->makeDraftBatch($policy, 'Progress 0 test');
            app(ChargeGenerationService::class)->activateBatch($batch);

            $batch->refresh();
            $this->assertSame(0, (int) $batch->progress_pct, 'No payments → 0%');
        });
    }

    // =========================================================================
    // Fix 1 — Guard: activating a template (is_initial=true) must throw
    // =========================================================================

    public function test_activate_template_batch_throws_invalid_argument(): void
    {
        $tenant = $this->makeTenant('ActivateTemplate Tenant');
        $this->seedTenant($tenant);

        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_TPL');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/plantilla inicial/i');

        $tenant->run(function () use ($policy): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

            // Simulate a template batch (is_initial=true) — the cron clones this; it
            // must NEVER be activated directly.
            $template = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Plantilla test',
                'year' => 2026,
                'month' => null,
                'cycle' => null,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2026-01-01',
                'due_date' => '2026-01-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
                'is_initial' => true,
            ]);

            // Must throw: templates are never activated directly.
            app(ChargeGenerationService::class)->activateBatch($template);
        });
    }

    public function test_activate_template_batch_generates_no_charges(): void
    {
        $tenant = $this->makeTenant('ActivateTemplateNoCharges Tenant');
        $this->seedTenant($tenant);

        $this->makeUnit($tenant);
        $policy = $this->makeEventualFixedPolicy($tenant, 70000, 'LIFE_TPLNC');

        $tenant->run(function () use ($policy): void {
            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

            $template = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Plantilla no-charges test',
                'year' => 2026,
                'month' => null,
                'cycle' => null,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => '2026-01-01',
                'due_date' => '2026-01-31',
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
                'is_initial' => true,
            ]);

            try {
                app(ChargeGenerationService::class)->activateBatch($template);
            } catch (\InvalidArgumentException) {
                // Expected — guard fires.
            }

            // The template must not have any charges created.
            $this->assertSame(
                0,
                Charge::where('charge_batch_id', $template->id)->count(),
                'A template batch must never carry charges, even after a failed activation attempt',
            );
        });
    }
}
