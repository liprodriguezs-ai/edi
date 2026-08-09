<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
use Modules\Collections\Services\ChargeService;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\User\Models\User;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Tests for charge-level mutations: discounts, cancellation guards, and HTTP PATCH/DELETE guards.
 *
 * Covers:
 *  - applyDiscount twice accumulates (additive)
 *  - Full condonation (discount == expected, paid == 0) → COMPLETED with no credit
 *  - cancel() blocked when charge has payment applications → throws
 *  - HTTP PATCH on charge with payment applications → 422
 *  - HTTP PATCH without payment applications → allows due_date/grace_days change
 *  - HTTP DELETE always → 422 ("charges are never deleted")
 */
final class CollectionsChargeMutationsTest extends TestCase
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
     * Create and activate a charge for a single unit. Must be called INSIDE tenant->run().
     */
    private function makeChargeForUnit(Unit $unit, int $amount, string $code, string $dueDate = '2099-12-31'): Charge
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

    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    private function attachResident(Tenant $tenant, Unit $unit, User $user, bool $billing = true): void
    {
        $tenant->run(function () use ($unit, $user, $billing): void {
            $relationshipId = UnitRelationship::query()->value('id');
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => $relationshipId,
                'is_billing_owner' => $billing,
            ]);
        });
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    // =========================================================================
    // applyDiscount twice accumulates (additive)
    // =========================================================================

    public function test_discount_accumulates_additively_across_two_calls(): void
    {
        $tenant = $this->makeTenant('Discount Additive Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'DISC1');
            $chargeService = app(ChargeService::class);

            // First discount: 20000
            $chargeService->applyDiscount($charge, 20000, 'Primera condonacion parcial', null);

            // Second discount: 15000
            $chargeService->applyDiscount($charge->refresh(), 15000, 'Segunda condonacion parcial', null);

            $charge->refresh();
            $discountRaw = (int) $charge->getAttributes()['discount_amount'];
            $this->assertSame(35000, $discountRaw, 'Two discounts must accumulate: 20000 + 15000 = 35000');

            // Effective expected = 70000 - 35000 = 35000; still owed → PARTIAL or PENDING (no payment)
            $effectiveExpected = app(ChargeStatusService::class)->effectiveExpected($charge);
            $this->assertSame(35000, $effectiveExpected, 'Effective expected must be 70000 - 35000 = 35000');
        });
    }

    // =========================================================================
    // Full condonation → COMPLETED with no credit created
    // =========================================================================

    public function test_full_condonation_marks_charge_completed_without_credit(): void
    {
        $tenant = $this->makeTenant('Full Condonation Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'DISC_FULL');
            $chargeService = app(ChargeService::class);

            // Condone the FULL amount (discount == expected, paid == 0)
            $result = $chargeService->applyDiscount($charge, 70000, 'Condonacion total de prueba', null);

            $result->refresh();
            $this->assertSame(ChargeStatus::COMPLETED, $result->charge_status_id, 'Full condonation must mark charge as COMPLETED');

            // No credit must have been created (discount ≠ payment, no credit balance added)
            $creditBalance = CreditBalance::where('unit_id', $unit->id)->first();
            $this->assertNull($creditBalance, 'Full condonation must NOT create credit balance');

            // paid_amount remains 0
            $paidRaw = (int) $result->getAttributes()['paid_amount'];
            $this->assertSame(0, $paidRaw, 'paid_amount must remain 0 after full condonation');
        });
    }

    // =========================================================================
    // Discount that would exceed outstanding → throws
    // =========================================================================

    public function test_discount_exceeding_outstanding_throws(): void
    {
        $tenant = $this->makeTenant('Discount Exceeds Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function () use ($unit): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'DISC_EXC');

            // Attempt to condone 70001 (> 70000 outstanding) → must throw
            app(ChargeService::class)->applyDiscount($charge, 70001, 'Supera el saldo pendiente', null);
        });
    }

    // =========================================================================
    // cancel() blocked when charge has payment applications
    // =========================================================================

    public function test_cancel_charge_with_payment_applications_throws(): void
    {
        $tenant = $this->makeTenant('CancelWithPayments Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);

        $this->expectException(\InvalidArgumentException::class);

        $tenant->run(function () use ($unit, $bank): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'CANCEL_PAY');

            // Make a partial payment → creates payment_application row
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => 30000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);

            $charge->refresh();
            $this->assertGreaterThan(0, $charge->payment_applications()->count(), 'Precondition: charge must have payment applications');

            // Attempt to cancel the charge → must throw because payments are applied
            app(ChargeService::class)->cancel($charge, 'Cancelacion no permitida');
        });
    }

    // =========================================================================
    // HTTP PATCH on charge with payment applications → 422
    // =========================================================================

    public function test_http_patch_charge_with_payment_applications_returns_422(): void
    {
        $tenant = $this->makeTenant('HTTP Patch Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $admin = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $admin);

        $charge = null;
        $tenant->run(function () use ($unit, $bank, &$charge): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'PATCH_PAY', '2099-12-31');

            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => 30000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);
        });

        $token = $this->adminToken($admin);
        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/charge/{$charge->id}", [
                'due_date' => '2099-11-30',
            ])
            ->assertStatus(422);
    }

    // =========================================================================
    // HTTP PATCH without payment applications → allows due_date/grace_days change
    // =========================================================================

    public function test_http_patch_charge_without_payments_allows_schedule_change(): void
    {
        $tenant = $this->makeTenant('HTTP Patch NoPay Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $admin = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $admin);

        $charge = null;
        $tenant->run(function () use ($unit, &$charge): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'PATCH_NOPAY', '2099-12-31');
        });

        $token = $this->adminToken($admin);
        $response = $this->tenantRequest($token, $tenant)
            ->patchJson("/api/charge/{$charge->id}", [
                'due_date' => '2099-11-30',
                'grace_days' => 5,
            ]);

        $response->assertStatus(200);

        $tenant->run(function () use ($charge): void {
            $charge->refresh();
            $this->assertSame('2099-11-30', $charge->due_date->format('Y-m-d'), 'due_date must be updated');
            $this->assertSame(5, (int) $charge->grace_days, 'grace_days must be updated');
        });
    }

    // =========================================================================
    // HTTP DELETE always → 422 (charges are never deleted)
    // =========================================================================

    public function test_http_delete_charge_always_returns_422(): void
    {
        $tenant = $this->makeTenant('HTTP Delete Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $admin = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $admin);

        $charge = null;
        $tenant->run(function () use ($unit, &$charge): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'DELETE_CHG', '2099-12-31');
        });

        $token = $this->adminToken($admin);
        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/charge/{$charge->id}")
            ->assertStatus(422);

        // Charge must still exist (not deleted)
        $tenant->run(function () use ($charge): void {
            $this->assertNotNull(Charge::find($charge->id), 'Charge must still exist after DELETE attempt');
        });
    }

    // =========================================================================
    // HTTP DELETE on charge with payment applications → still 422 (double guard)
    // =========================================================================

    public function test_http_delete_charge_with_payments_also_returns_422(): void
    {
        $tenant = $this->makeTenant('HTTP Delete WithPay Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bank = $this->makeBankAccount($tenant, 0);
        $admin = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $admin);

        $charge = null;
        $tenant->run(function () use ($unit, $bank, &$charge): void {
            $charge = $this->makeChargeForUnit($unit, 70000, 'DELETE_PAY', '2099-12-31');

            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-01-05',
                'total_amount' => 70000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);
        });

        $token = $this->adminToken($admin);
        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/charge/{$charge->id}")
            ->assertStatus(422);
    }
}
