<?php

declare(strict_types=1);

namespace Modules\Collections\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\ChargeSurchargeRule;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Models\SurchargeMode;
use Modules\Collections\Services\ChargeStatusService;
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
 * FIFO must collect principal + accrued surcharge of the OLDEST charge before any newer
 * charge. The surcharge raises effective_expected, so a single payment is consumed against
 * the oldest charge's full owed (principal + recargo) first.
 */
final class SurchargeFifoTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected $connectionsToTransact = [];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('Surcharge FIFO Tenant');
        $this->seedTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_payment_covers_oldest_charge_principal_plus_surcharge_first(): void
    {
        $this->tenant->run(function (): void {
            // Today = Aug 20, 2026. Both charges overdue by > 1 month so each accrues a fee.
            Carbon::setTestNow(Carbon::create(2026, 8, 20, 9));

            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ]);

            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
            $concept = ChargeConcept::create([
                'code' => 'FIFO_'.strtoupper(substr(md5(uniqid()), 0, 6)),
                'name' => 'FIFO concept',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'FIFO policy',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 1000_00,
                'grace_days' => 5,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => '2020-01-01',
                'valid_to' => '2030-12-31',
                'is_active' => true,
            ]);

            // 5% one_time of the overdue base, from month 1.
            ChargeSurchargeRule::create([
                'charge_policy_id' => $policy->id,
                'from_month' => 1,
                'surcharge_mode_id' => SurchargeMode::PERCENT,
                'surcharge_value' => 500,
                'recurrence' => ChargeSurchargeRule::RECURRENCE_ONE_TIME,
                'cap_amount' => null,
                'is_active' => true,
            ]);
            $policy->load('surcharge_rules');

            // Oldest charge: due Jun 10 (overdue Jun 15, > 1 month overdue on Aug 20).
            $oldCharge = $this->makeCharge($policy, $unit, '2026-06-10');
            // Newer charge: due Jul 10.
            $newCharge = $this->makeCharge($policy, $unit, '2026-07-10');

            // Accrue surcharge on both (as the daily cron would).
            $statusService = app(ChargeStatusService::class);
            $statusService->accrueSurcharge($oldCharge, $policy);
            $statusService->accrueSurcharge($newCharge, $policy);

            // Each charge owes 1000.00 principal + 50.00 surcharge = 1050.00.
            $this->assertSame(5000, (int) $oldCharge->fresh()->getAttributes()['surcharge_amount']);
            $this->assertSame(5000, (int) $newCharge->fresh()->getAttributes()['surcharge_amount']);

            $bank = $this->makeBankAccount();

            // Pay exactly the oldest charge's full owed (principal + recargo) = 1050.00.
            $paymentService = app(PaymentService::class);
            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-08-20',
                'total_amount' => 1050_00,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $bank->id,
            ], true);

            $oldCharge->refresh();
            $newCharge->refresh();

            // FIFO: the OLDEST charge is fully covered (principal + surcharge), the newer untouched.
            $this->assertSame(1050_00, (int) $oldCharge->getAttributes()['paid_amount'], 'Oldest charge fully paid incl. surcharge');
            $this->assertSame(0, (int) $newCharge->getAttributes()['paid_amount'], 'Newer charge untouched');

            // Oldest is now completed (effective_expected == paid).
            $this->assertSame(ChargeStatus::COMPLETED, $oldCharge->charge_status_id);

            // Newer charge still owes principal + surcharge → overdue once its status is
            // recomputed (the daily cron does this; the payment flow only touches charges
            // it applied to).
            $statusService->recomputeCharge($newCharge);
            $this->assertSame(ChargeStatus::OVERDUE, $newCharge->fresh()->charge_status_id);
        });
    }

    private function makeCharge(ChargePolicy $policy, Unit $unit, string $dueDate): Charge
    {
        return Charge::create([
            'charge_policy_id' => $policy->id,
            'charge_concept_id' => $policy->charge_concept_id,
            'unit_id' => $unit->id,
            'expected_amount' => 1000_00,
            'paid_amount' => 0,
            'issued_at' => $dueDate,
            'due_date' => $dueDate,
            'grace_days' => 5,
            'charge_status_id' => ChargeStatus::PENDING,
            'is_cancelled' => false,
        ]);
    }

    private function makeBankAccount(): BankAccount
    {
        return BankAccount::create([
            'name' => 'Caja FIFO',
            'bank_account_type_id' => BankAccountType::CASH,
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_primary' => true,
            'is_active' => true,
        ]);
    }
}
