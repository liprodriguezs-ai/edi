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
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargeGenerationService;
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
 * Tests for `collections:send-reminders` command.
 *
 * Covers:
 *  - Charge due in 7 days → reminder dispatched
 *  - Charge due in 5 days → no reminder (not in default [7, 3] list)
 *  - Charge due today → reminder dispatched (on_due_day = true)
 *  - Charge overdue exactly 15 days → reminder dispatched (interval 15)
 *  - Charge overdue 10 days → no reminder (10 % 15 ≠ 0)
 *  - COMPLETED charge → never reminded (filtered out)
 *  - Count verified from command output
 */
final class CollectionsRemindersTest extends TestCase
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
     * Create a charge for a unit (targeting single_unit). Must be called OUTSIDE tenant->run().
     * Returns the Charge.
     */
    private function makeChargeForUnit(Tenant $tenant, Unit $unit, int $amount, string $code, string $dueDate, int $graceDays = 0): Charge
    {
        $charge = null;
        $tenant->run(function () use (&$charge, $unit, $amount, $code, $dueDate, $graceDays): void {
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

            $charge = Charge::where('charge_batch_id', $batch->id)
                ->where('unit_id', $unit->id)
                ->firstOrFail();
        });

        return $charge;
    }

    // =========================================================================
    // Charge due in 7 days → reminder sent
    // =========================================================================

    public function test_charge_due_in_7_days_triggers_reminder(): void
    {
        $tenant = $this->makeTenant('Reminder7 Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);

        // Today = July 8 → due July 15 = exactly 7 days before (in default list)
        Carbon::setTestNow(Carbon::parse('2026-07-08'));
        $this->makeChargeForUnit($tenant, $unit, 70000, 'REM7', '2026-07-15');

        $this->artisan('collections:send-reminders')
            ->expectsOutputToContain('1 charge reminder(s) dispatched')
            ->assertSuccessful();
    }

    // =========================================================================
    // Charge due in 5 days → no reminder (not in [7, 3])
    // =========================================================================

    public function test_charge_due_in_5_days_sends_no_reminder(): void
    {
        $tenant = $this->makeTenant('Reminder5 Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);

        // Today = July 10 → due July 15 = 5 days before (NOT in [7, 3])
        Carbon::setTestNow(Carbon::parse('2026-07-10'));
        $this->makeChargeForUnit($tenant, $unit, 70000, 'REM5', '2026-07-15');

        $this->artisan('collections:send-reminders')
            ->expectsOutputToContain('0 charge reminder(s) dispatched')
            ->assertSuccessful();
    }

    // =========================================================================
    // Charge due today → reminder sent (on_due_day = true)
    // =========================================================================

    public function test_charge_due_today_triggers_reminder(): void
    {
        $tenant = $this->makeTenant('ReminderToday Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);

        Carbon::setTestNow(Carbon::parse('2026-07-15'));
        $this->makeChargeForUnit($tenant, $unit, 70000, 'REMDUE', '2026-07-15');

        $this->artisan('collections:send-reminders')
            ->expectsOutputToContain('1 charge reminder(s) dispatched')
            ->assertSuccessful();
    }

    // =========================================================================
    // Charge overdue exactly 15 days → reminder sent (overdue_every_days = 15)
    // =========================================================================

    public function test_charge_overdue_15_days_triggers_reminder(): void
    {
        $tenant = $this->makeTenant('ReminderOverdue15 Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);

        // Due July 1, today = July 16 (15 days past due, 15 % 15 = 0 → reminder)
        Carbon::setTestNow(Carbon::parse('2026-07-16'));
        $this->makeChargeForUnit($tenant, $unit, 70000, 'REMOD15', '2026-07-01');

        $this->artisan('collections:send-reminders')
            ->expectsOutputToContain('1 charge reminder(s) dispatched')
            ->assertSuccessful();
    }

    // =========================================================================
    // Charge overdue 10 days → no reminder (10 % 15 ≠ 0)
    // =========================================================================

    public function test_charge_overdue_10_days_sends_no_reminder(): void
    {
        $tenant = $this->makeTenant('ReminderOverdue10 Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);

        // Due July 1, today = July 11 (10 days past, 10 % 15 ≠ 0 → no reminder)
        Carbon::setTestNow(Carbon::parse('2026-07-11'));
        $this->makeChargeForUnit($tenant, $unit, 70000, 'REMOD10', '2026-07-01');

        $this->artisan('collections:send-reminders')
            ->expectsOutputToContain('0 charge reminder(s) dispatched')
            ->assertSuccessful();
    }

    // =========================================================================
    // COMPLETED charge → never reminded
    // =========================================================================

    public function test_completed_charge_never_triggers_reminder(): void
    {
        $tenant = $this->makeTenant('ReminderCompleted Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $bank = $this->makeBankAccount($tenant, 0);

        // Today = July 8 (7 days before due = normally would trigger reminder)
        Carbon::setTestNow(Carbon::parse('2026-07-08'));
        $this->makeChargeForUnit($tenant, $unit, 70000, 'REMCOMP', '2026-07-15');

        // Pay fully → COMPLETED
        $tenant->run(function () use ($unit, $bank): void {
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-07-08',
                'total_amount' => 70000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
            ]);

            $charge = Charge::where('unit_id', $unit->id)->firstOrFail();
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id, 'Precondition: charge must be COMPLETED');
        });

        // Command should dispatch 0 reminders (COMPLETED filtered out)
        $this->artisan('collections:send-reminders')
            ->expectsOutputToContain('0 charge reminder(s) dispatched')
            ->assertSuccessful();
    }

    // =========================================================================
    // Multiple charges: some match, some don't — count is correct
    // =========================================================================

    public function test_only_matching_offset_charges_are_counted(): void
    {
        $tenant = $this->makeTenant('ReminderMultiple Tenant');
        $this->seedTenant($tenant);

        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);
        $unit3 = $this->makeUnit($tenant);

        $resident1 = $this->makeUser($tenant);
        $resident2 = $this->makeUser($tenant);
        $resident3 = $this->makeUser($tenant);

        $this->attachResident($tenant, $unit1, $resident1);
        $this->attachResident($tenant, $unit2, $resident2);
        $this->attachResident($tenant, $unit3, $resident3);

        // Today = July 8 → 7 days before July 15
        Carbon::setTestNow(Carbon::parse('2026-07-08'));

        // unit1: due July 15 → 7 days before → REMINDER
        $this->makeChargeForUnit($tenant, $unit1, 70000, 'REMM1', '2026-07-15');

        // unit2: due July 12 → 4 days before → NO REMINDER (not in [7, 3])
        $this->makeChargeForUnit($tenant, $unit2, 70000, 'REMM2', '2026-07-12');

        // unit3: due July 11 → 3 days before → REMINDER
        $this->makeChargeForUnit($tenant, $unit3, 70000, 'REMM3', '2026-07-11');

        $this->artisan('collections:send-reminders')
            ->expectsOutputToContain('2 charge reminder(s) dispatched')
            ->assertSuccessful();
    }
}
