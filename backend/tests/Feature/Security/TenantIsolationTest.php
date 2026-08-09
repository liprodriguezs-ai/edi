<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

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
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Cross-tenant isolation: a user of tenant A must NOT be able to read a resource
 * that belongs to tenant B. The global TenantScope makes the row invisible, so
 * Orion resource `show` returns 404. This is the single highest-severity property
 * of the single-database multi-tenant model; Housing, Treasury and Projects had
 * no coverage of it.
 */
final class TenantIsolationTest extends TestCase
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

    public function test_housing_unit_of_another_tenant_is_not_readable(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $foreignUnitId = $this->inTenant($tenantB, function (): string {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            return Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => '999',
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ])->id;
        });

        $this->tenantRequest($tokenA, $tenantA)
            ->getJson("/api/unit/{$foreignUnitId}")
            ->assertStatus(404);
    }

    public function test_treasury_bank_account_of_another_tenant_is_not_readable(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $foreignAccountId = $this->inTenant($tenantB, function (): string {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));

            return BankAccount::create([
                'name' => 'Caja del condominio B',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => 500000,
                'current_balance' => 500000,
                'is_primary' => true,
                'is_active' => true,
            ])->id;
        });

        $this->tenantRequest($tokenA, $tenantA)
            ->getJson("/api/bank_account/{$foreignAccountId}")
            ->assertStatus(404);
    }

    public function test_projects_community_project_of_another_tenant_is_not_readable(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $foreignProjectId = $this->inTenant($tenantB, function (): string {
            $status = CommunityProjectStatus::findOrFail(CommunityProjectStatus::DRAFT);
            $category = CommunityProjectCategory::findOrFail(Catalog::id(CommunityProjectCategory::class, 'Obra'));
            $participationType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $amountMode = AmountMode::findOrFail(AmountMode::FIXED_REQUIRED);

            return CommunityProject::create([
                'title' => 'Proyecto del condominio B',
                'description' => 'No debe verse desde el condominio A',
                'community_project_category_id' => $category->id,
                'participation_type_id' => $participationType->id,
                'amount_mode_id' => $amountMode->id,
                'fixed_amount' => 120000,
                'allow_decline' => true,
                'target_type' => 'all',
                'target_selector' => null,
                'start_date' => '2026-04-01',
                'due_date' => '2026-08-31',
                'visible_to_residents' => true,
                'allow_comments' => true,
                'community_project_status_id' => $status->id,
            ])->id;
        });

        $this->tenantRequest($tokenA, $tenantA)
            ->getJson("/api/community_project/{$foreignProjectId}")
            ->assertStatus(404);
    }

    public function test_collections_charge_of_another_tenant_is_not_readable(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $foreignChargeId = $this->inTenant($tenantB, function (): string {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => '801',
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ]);

            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Recargo'));
            $concept = ChargeConcept::create([
                'code' => 'ISO-CHG-01',
                'name' => 'Cargo aislamiento B',
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);
            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Política aislamiento B',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 50000,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'grace_days' => 0,
            ]);

            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => 'Batch aislamiento B',
                'year' => 2026,
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'issued_at' => '2026-01-01',
                'due_date' => '2099-12-31',
                'charge_batch_status_id' => ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT)->id,
                'progress_pct' => 0,
            ]);

            app(ChargeGenerationService::class)->activateBatch($batch);

            return Charge::where('charge_batch_id', $batch->id)
                ->where('unit_id', $unit->id)
                ->firstOrFail()
                ->id;
        });

        $this->tenantRequest($tokenA, $tenantA)
            ->getJson("/api/charge/{$foreignChargeId}")
            ->assertStatus(404);
    }

    public function test_collections_payment_of_another_tenant_is_not_readable(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->twoTenants();

        $foreignPaymentId = $this->inTenant($tenantB, function (): string {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => '802',
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.0,
            ]);

            $bankType = BankAccountType::findOrFail(BankAccountType::CASH);
            $bankPurpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $bank = BankAccount::create([
                'name' => 'Caja condominio B',
                'bank_account_type_id' => $bankType->id,
                'bank_account_purpose_id' => $bankPurpose->id,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);

            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            return Payment::create([
                'unit_id' => $unit->id,
                'paid_at' => '2026-06-01',
                'total_amount' => 50000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bank->id,
                'receipt_number' => 'R-2026-ISO-001',
                'payment_status_id' => PaymentStatus::CONFIRMED,
            ])->id;
        });

        $this->tenantRequest($tokenA, $tenantA)
            ->getJson("/api/payment/{$foreignPaymentId}")
            ->assertStatus(404);
    }
}
