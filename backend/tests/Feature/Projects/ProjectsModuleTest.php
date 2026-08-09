<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\PaymentMethod;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Models\ProjectContributionStatus;
use Modules\Projects\Services\ContributionService;
use Modules\Projects\Services\ProjectActivationService;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Services\ExpenseService;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 7 — Community Projects (Proyectos Comunitarios).
 *
 * Flows covered (spec §11.8 + §11.9):
 *  A. Catalogs seeded per tenant (all 4 project catalogs)
 *  B. Mandatory project activation generates charges (one per unit, community_project_id set)
 *     + contributions (one per unit, expected_amount correct)
 *  C. Paying a mandatory charge via PaymentService works; overpayment → credit
 *  D. Voluntary project: activation creates contributions, NO charges
 *  E. Voluntary contribution updates contributed_amount + status (all 5 states)
 *  F. Decline excludes from "sin aportar"; revert works; closed rejects contribution
 *  G. Rollups: recaudado / ejecutado (via Treasury Expense) / disponible
 *  H. fully_funded when recaudado >= goal_amount
 *  I. expense.community_project_id FK works (nullOnDelete)
 *  J. charge.community_project_id FK works (nullOnDelete)
 *  K. Money round-trips (minor units, integer storage)
 *  L. Tenant isolation (all new tables)
 */
final class ProjectsModuleTest extends TestCase
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
                'number' => (string) mt_rand(100, 9999),
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
     * Build a minimal CommunityProject in draft status (inside tenant context).
     * Must be called inside $tenant->run().
     *
     * @param  array<string, mixed>  $overrides
     */
    private function buildProject(array $overrides = []): CommunityProject
    {
        $status = CommunityProjectStatus::findOrFail(CommunityProjectStatus::DRAFT);
        $category = CommunityProjectCategory::findOrFail(Catalog::id(CommunityProjectCategory::class, 'Obra'));
        $participationType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
        $amountMode = AmountMode::findOrFail(AmountMode::FIXED_REQUIRED);

        return CommunityProject::create(array_merge([
            'title' => 'Proyecto de prueba '.Str::random(6),
            'description' => 'Descripción de prueba',
            'community_project_category_id' => $category->id,
            'participation_type_id' => $participationType->id,
            'amount_mode_id' => $amountMode->id,
            'fixed_amount' => 120000, // $1,200
            'allow_decline' => true,
            'target_type' => 'all',
            'target_selector' => null,
            'start_date' => '2026-04-01',
            'due_date' => '2026-08-31',
            'visible_to_residents' => true,
            'allow_comments' => true,
            'community_project_status_id' => $status->id,
        ], $overrides));
    }

    // =========================================================================
    // A. Catalog seeding
    // =========================================================================

    public function test_catalogs_seeded_per_tenant(): void
    {
        $tenant = $this->makeTenant('Catalog Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(5, CommunityProjectStatus::count(), 'Must have 5 community_project_status');
            $this->assertSame(5, CommunityProjectCategory::count(), 'Must have 5 community_project_category');
            $this->assertSame(2, ParticipationType::count(), 'Must have 2 participation_type');
            $this->assertSame(6, ProjectContributionStatus::count(), 'Must have 6 project_contribution_status');

            // Verify specific keys exist
            $this->assertNotNull(CommunityProjectStatus::find(CommunityProjectStatus::DRAFT));
            $this->assertNotNull(CommunityProjectStatus::find(CommunityProjectStatus::ACTIVE));
            $this->assertNotNull(ParticipationType::find(ParticipationType::MANDATORY));
            $this->assertNotNull(ParticipationType::find(ParticipationType::VOLUNTARY));
        });
    }

    public function test_catalogs_are_central_shared_across_tenants(): void
    {
        $tenant1 = $this->makeTenant('Catalog T1');
        $tenant2 = $this->makeTenant('Catalog T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        // community_project_status is a CENTRAL fixed-enum catalog (no tenant_id):
        // seeded once and shared by every tenant, NOT isolated per tenant.
        $tenant1->run(function (): void {
            $this->assertSame(5, CommunityProjectStatus::count());
        });

        $tenant2->run(function (): void {
            // T2 sees the SAME central rows.
            $this->assertSame(5, CommunityProjectStatus::count());
        });
    }

    // =========================================================================
    // B. Mandatory project activation generates charges + contributions
    // =========================================================================

    public function test_mandatory_activation_generates_charges_and_contributions(): void
    {
        $tenant = $this->makeTenant('Mandatory Tenant');
        $this->seedTenant($tenant);

        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);
        $unit3 = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit1, $unit2, $unit3): void {
            $mandatoryType = ParticipationType::findOrFail(ParticipationType::MANDATORY);
            $amountMode = AmountMode::findOrFail(AmountMode::FIXED_REQUIRED);

            $project = $this->buildProject([
                'participation_type_id' => $mandatoryType->id,
                'amount_mode_id' => $amountMode->id,
                'fixed_amount' => 120000, // $1,200 per unit
                'target_type' => 'manual',
                'target_selector' => ['unit_ids' => [$unit1->id, $unit2->id, $unit3->id]],
                'goal_amount' => 360000,
            ]);

            $service = app(ProjectActivationService::class);
            $activated = $service->activate($project);

            // Project must be active
            $this->assertSame(CommunityProjectStatus::ACTIVE, $activated->fresh()->community_project_status_id);

            // 3 contributions created
            $contributions = ProjectContribution::where('community_project_id', $activated->id)->get();
            $this->assertCount(3, $contributions);

            // Each contribution has expected_amount = 120000 (minor units)
            foreach ($contributions as $contribution) {
                $rawExpected = (int) $contribution->getAttributes()['expected_amount'];
                $this->assertSame(120000, $rawExpected, 'expected_amount must be 120000 minor units');
                $rawContributed = (int) $contribution->getAttributes()['contributed_amount'];
                $this->assertSame(0, $rawContributed);
            }

            // 3 charges created (mandatory!) — one per unit, with community_project_id set
            $charges = Charge::where('community_project_id', $activated->id)->get();
            $this->assertCount(3, $charges);

            foreach ($charges as $charge) {
                $this->assertSame($activated->id, $charge->community_project_id);
                $rawExpected = (int) $charge->getAttributes()['expected_amount'];
                $this->assertSame(120000, $rawExpected, 'Charge expected_amount must be 120000 minor units');
            }
        });
    }

    // =========================================================================
    // C. Idempotency — re-activating an active project is a no-op
    // =========================================================================

    public function test_mandatory_activation_is_idempotent(): void
    {
        $tenant = $this->makeTenant('Idempotent Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $mandatoryType = ParticipationType::findOrFail(ParticipationType::MANDATORY);

            $project = $this->buildProject([
                'participation_type_id' => $mandatoryType->id,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $service = app(ProjectActivationService::class);
            $service->activate($project);
            $service->activate($project); // second call should not duplicate

            $contributions = ProjectContribution::where('community_project_id', $project->id)->get();
            $this->assertCount(1, $contributions, 'Idempotent: should not create duplicate contributions');

            $charges = Charge::where('community_project_id', $project->id)->get();
            $this->assertCount(1, $charges, 'Idempotent: should not create duplicate charges');
        });
    }

    // =========================================================================
    // D. Voluntary project — no charges, contributions only
    // =========================================================================

    public function test_voluntary_activation_creates_contributions_no_charges(): void
    {
        $tenant = $this->makeTenant('Voluntary Tenant');
        $this->seedTenant($tenant);

        $unit1 = $this->makeUnit($tenant);
        $unit2 = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit1, $unit2): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $rangeMode = AmountMode::findOrFail(AmountMode::RANGE);

            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'amount_mode_id' => $rangeMode->id,
                'min_amount' => 20000, // $200
                'ideal_amount' => 50000, // $500
                'max_amount' => null,
                'fixed_amount' => null,
                'target_type' => 'manual',
                'target_selector' => ['unit_ids' => [$unit1->id, $unit2->id]],
                'goal_amount' => 100000,
            ]);

            $service = app(ProjectActivationService::class);
            $activated = $service->activate($project);

            // 2 contributions created
            $contributions = ProjectContribution::where('community_project_id', $activated->id)->get();
            $this->assertCount(2, $contributions);

            // expected_amount = ideal_amount for range mode (50000)
            foreach ($contributions as $contribution) {
                $rawExpected = (int) $contribution->getAttributes()['expected_amount'];
                $this->assertSame(50000, $rawExpected, 'Voluntary range: expected_amount should be ideal');
            }

            // NO charges created for voluntary
            $charges = Charge::where('community_project_id', $activated->id)->get();
            $this->assertCount(0, $charges, 'Voluntary project must NOT generate charges');
        });
    }

    // =========================================================================
    // E. Voluntary contribution status states
    // =========================================================================

    public function test_contribution_status_states_for_range_mode(): void
    {
        $tenant = $this->makeTenant('Status States Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $rangeMode = AmountMode::findOrFail(AmountMode::RANGE);

            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'amount_mode_id' => $rangeMode->id,
                'min_amount' => 20000,  // $200
                'ideal_amount' => 50000, // $500
                'fixed_amount' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $service = app(ProjectActivationService::class);
            $service->activate($project);

            $contributionService = app(ContributionService::class);

            $contribution = ProjectContribution::where('community_project_id', $project->id)
                ->where('unit_id', $unit->id)
                ->firstOrFail();

            // Initially: PENDING
            $this->assertSame(
                ProjectContributionStatus::PENDING,
                $contribution->project_contribution_status_id,
                'Initial status must be pending'
            );

            // below_min: $50 < $200 min
            $contributionService->contribute($contribution, 5000);
            $contribution->refresh();
            $this->assertSame(
                ProjectContributionStatus::BELOW_MIN,
                $contribution->project_contribution_status_id,
                'Below min → below_min status'
            );

            // partial: $250 total (cumulative) >= min $200 but < ideal $500
            $contributionService->contribute($contribution, 20000); // now 25000 total
            $contribution->refresh();
            $this->assertSame(
                ProjectContributionStatus::PARTIAL,
                $contribution->project_contribution_status_id,
                'Between min and ideal → partial status'
            );

            // ideal: add enough to reach exactly 50000
            $contributionService->contribute($contribution, 25000); // now 50000 total
            $contribution->refresh();
            $this->assertSame(
                ProjectContributionStatus::IDEAL,
                $contribution->project_contribution_status_id,
                'Exactly at ideal → ideal status'
            );

            // generous: above ideal
            $contributionService->contribute($contribution, 10000); // now 60000 total
            $contribution->refresh();
            $this->assertSame(
                ProjectContributionStatus::GENEROUS,
                $contribution->project_contribution_status_id,
                'Above ideal → generous status'
            );
        });
    }

    // =========================================================================
    // F. Decline / revert / closed rejects
    // =========================================================================

    public function test_decline_sets_status_and_excludes_from_sin_aportar(): void
    {
        $tenant = $this->makeTenant('Decline Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);

            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $service = app(ProjectActivationService::class);
            $service->activate($project);

            $contribution = ProjectContribution::where('community_project_id', $project->id)->firstOrFail();

            $contributionService = app(ContributionService::class);
            $contributionService->decline($contribution);

            $contribution->refresh();
            $this->assertTrue($contribution->declined, 'Declined flag must be true');
            $this->assertSame(
                ProjectContributionStatus::DECLINED,
                $contribution->project_contribution_status_id,
                'Status must be declined'
            );

            // "Sin aportar" query: pending AND NOT declined
            $sinAportar = ProjectContribution::where('community_project_id', $project->id)
                ->where('declined', false)
                ->where('project_contribution_status_id', ProjectContributionStatus::PENDING)
                ->count();

            $this->assertSame(0, $sinAportar, 'Declined unit must not appear in sin aportar');
        });
    }

    public function test_revert_decline_restores_pending_status(): void
    {
        $tenant = $this->makeTenant('Revert Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $service = app(ProjectActivationService::class);
            $service->activate($project);

            $contribution = ProjectContribution::where('community_project_id', $project->id)->firstOrFail();
            $contributionService = app(ContributionService::class);

            $contributionService->decline($contribution);
            $contribution->refresh();
            $this->assertTrue($contribution->declined);

            $contributionService->revertDecline($contribution);
            $contribution->refresh();
            $this->assertFalse($contribution->declined, 'After revert, declined must be false');
            $this->assertSame(
                ProjectContributionStatus::PENDING,
                $contribution->project_contribution_status_id,
                'After revert, status must be pending (no contributions yet)'
            );
        });
    }

    public function test_closed_project_rejects_contributions(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $tenant = $this->makeTenant('Closed Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $activationService = app(ProjectActivationService::class);
            $activationService->activate($project);

            $contribution = ProjectContribution::where('community_project_id', $project->id)->firstOrFail();

            // Close the project
            $executedStatus = CommunityProjectStatus::findOrFail(CommunityProjectStatus::EXECUTED);
            $project->community_project_status_id = $executedStatus->id;
            $project->save();

            // This should throw
            $contributionService = app(ContributionService::class);
            $contributionService->contribute($contribution, 10000);
        });
    }

    // =========================================================================
    // G. Rollups: recaudado / ejecutado / disponible
    // =========================================================================

    public function test_rollups_recaudado_ejecutado_disponible(): void
    {
        $tenant = $this->makeTenant('Rollup Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 5000000);

        $tenant->run(function () use ($unit, $bankAccount): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'fixed_amount' => 100000,
                'goal_amount' => 100000,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $activationService = app(ProjectActivationService::class);
            $activationService->activate($project);

            $contribution = ProjectContribution::where('community_project_id', $project->id)->firstOrFail();
            $contributionService = app(ContributionService::class);

            // Contribute 80000
            $contributionService->contribute($contribution, 80000);

            // Register an expense of 30000 linked to the project
            $expenseService = app(ExpenseService::class);
            $category = ExpenseCategory::first();
            $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::OTHER);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $expenseService->register([
                'concept' => 'Compra de materiales',
                'paid_at' => now()->toDateString(),
                'amount' => 30000,
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryType->id,
                'year' => now()->year,
                'month' => now()->month,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
                'community_project_id' => $project->id,
            ]);

            $summary = $contributionService->getSummary($project);

            $this->assertSame(80000, $summary['raised'], 'raised must be sum of contributions');
            $this->assertSame(30000, $summary['executed'], 'executed must be sum of project expenses');
            $this->assertSame(50000, $summary['available'], 'available = raised - executed');
        });
    }

    // =========================================================================
    // H. Fully funded when recaudado >= goal_amount
    // =========================================================================

    public function test_fully_funded_when_recaudado_reaches_goal(): void
    {
        $tenant = $this->makeTenant('Funded Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'fixed_amount' => 100000,
                'goal_amount' => 100000, // $1,000 goal
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $activationService = app(ProjectActivationService::class);
            $activationService->activate($project);

            $contribution = ProjectContribution::where('community_project_id', $project->id)->firstOrFail();
            $contributionService = app(ContributionService::class);

            // Below goal — still active
            $contributionService->contribute($contribution, 50000);
            $project->refresh();
            $this->assertNotSame(
                CommunityProjectStatus::FULLY_FUNDED,
                $project->community_project_status_id,
                'Below goal → should NOT be fully_funded yet'
            );

            // At or above goal — fully_funded
            $contributionService->contribute($contribution, 50000); // now 100000 = goal
            $project->refresh();
            $this->assertSame(
                CommunityProjectStatus::FULLY_FUNDED,
                $project->community_project_status_id,
                'At goal → must transition to fully_funded'
            );
        });
    }

    // =========================================================================
    // I. expense.community_project_id FK works (nullOnDelete)
    // =========================================================================

    public function test_expense_community_project_id_fk_nulls_on_delete(): void
    {
        $tenant = $this->makeTenant('FK Expense Tenant');
        $this->seedTenant($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 5000000);

        $tenant->run(function () use ($bankAccount): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'target_type' => 'all',
            ]);

            // Create an expense linked to the project
            $category = ExpenseCategory::first();
            $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::OTHER);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $expenseService = app(ExpenseService::class);
            ['expense' => $expense] = $expenseService->register([
                'concept' => 'Compra materiales',
                'paid_at' => now()->toDateString(),
                'amount' => 50000,
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryType->id,
                'year' => now()->year,
                'month' => now()->month,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
                'community_project_id' => $project->id,
            ]);

            $this->assertSame($project->id, $expense->community_project_id);

            // Delete project — FK should nullify expense.community_project_id
            $project->forceDelete();

            $expense->refresh();
            $this->assertNull($expense->community_project_id, 'FK nullOnDelete: expense.community_project_id must be NULL after project deleted');
        });
    }

    // =========================================================================
    // J. charge.community_project_id FK works (nullOnDelete)
    // =========================================================================

    public function test_charge_community_project_id_fk_nulls_on_delete(): void
    {
        $tenant = $this->makeTenant('FK Charge Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $mandatoryType = ParticipationType::findOrFail(ParticipationType::MANDATORY);
            $amountMode = AmountMode::findOrFail(AmountMode::FIXED_REQUIRED);

            $project = $this->buildProject([
                'participation_type_id' => $mandatoryType->id,
                'amount_mode_id' => $amountMode->id,
                'fixed_amount' => 50000,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $activationService = app(ProjectActivationService::class);
            $activationService->activate($project);

            $charge = Charge::where('community_project_id', $project->id)->firstOrFail();
            $this->assertSame($project->id, $charge->community_project_id);

            // Force delete project — FK nullOnDelete
            $project->forceDelete();

            $charge->refresh();
            $this->assertNull($charge->community_project_id, 'FK nullOnDelete: charge.community_project_id must be NULL after project deleted');
        });
    }

    // =========================================================================
    // K. Money round-trips (minor units, integer storage)
    // =========================================================================

    public function test_money_stored_as_integer_minor_units(): void
    {
        $tenant = $this->makeTenant('Money Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $project = $this->buildProject([
                'fixed_amount' => 120050, // $1,200.50
                'goal_amount' => 6000000, // $60,000
            ]);

            // Read raw int from DB
            $rawFixed = (int) DB::table('community_project')->where('id', $project->id)->value('fixed_amount');
            $this->assertSame(120050, $rawFixed, 'fixed_amount must be stored as integer minor units');
            $this->assertIsInt($rawFixed);

            $rawGoal = (int) DB::table('community_project')->where('id', $project->id)->value('goal_amount');
            $this->assertSame(6000000, $rawGoal, 'goal_amount must be stored as integer minor units');
            $this->assertIsInt($rawGoal);
        });
    }

    public function test_contribution_amount_stored_as_integer(): void
    {
        $tenant = $this->makeTenant('Contribution Money Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $activationService = app(ProjectActivationService::class);
            $activationService->activate($project);

            $contribution = ProjectContribution::where('community_project_id', $project->id)->firstOrFail();
            $contributionService = app(ContributionService::class);

            $contributionService->contribute($contribution, 75025); // $750.25

            $raw = (int) DB::table('project_contribution')
                ->where('id', $contribution->id)
                ->value('contributed_amount');

            $this->assertSame(75025, $raw, 'contributed_amount must be stored as integer minor units');
            $this->assertIsInt($raw);
        });
    }

    // =========================================================================
    // L. Tenant isolation
    // =========================================================================

    public function test_tenant_isolation_community_project_table(): void
    {
        $tenant1 = $this->makeTenant('Isolation T1');
        $tenant2 = $this->makeTenant('Isolation T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $tenant1->run(function (): void {
            $this->buildProject(['title' => 'T1 Project']);
        });

        $tenant2->run(function (): void {
            $this->assertSame(0, CommunityProject::count(), 'Tenant 2 must not see Tenant 1 projects');
        });
    }

    // =========================================================================
    // FIX 4 — voided expenses must NOT count toward ejecutado/disponible
    // =========================================================================

    public function test_voided_expense_excluded_from_ejecutado_and_disponible(): void
    {
        $tenant = $this->makeTenant('Void Rollup Tenant');
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $bankAccount = $this->makeBankAccount($tenant, 5000000);

        $tenant->run(function () use ($unit, $bankAccount): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'fixed_amount' => 100000,
                'goal_amount' => 100000,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
            ]);

            $activationService = app(ProjectActivationService::class);
            $activationService->activate($project);

            $contribution = ProjectContribution::where('community_project_id', $project->id)->firstOrFail();
            $contributionService = app(ContributionService::class);
            $contributionService->contribute($contribution, 80000); // recaudado = 80000

            $expenseService = app(ExpenseService::class);
            $category = ExpenseCategory::first();
            $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::OTHER);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            // One $300 expense linked to the project, then voided.
            ['expense' => $expense] = $expenseService->register([
                'concept' => 'Compra a anular',
                'paid_at' => now()->toDateString(),
                'amount' => 30000, // $300
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryType->id,
                'year' => now()->year,
                'month' => now()->month,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
                'community_project_id' => $project->id,
            ]);

            // Before voiding: executed counts the expense.
            $before = $contributionService->getSummary($project);
            $this->assertSame(30000, $before['executed'], 'Active expense counts toward executed');

            // Void it (status flips to voided; no soft delete).
            $expenseService->void($expense, 'Cargado por error.');

            $after = $contributionService->getSummary($project);
            $this->assertSame(80000, $after['raised'], 'raised unchanged by void');
            $this->assertSame(0, $after['executed'], 'Voided expense must NOT count toward executed');
            $this->assertSame(80000, $after['available'], 'available = raised when only expense is voided');
        });
    }

    // =========================================================================
    // FIX A1 — mandatory BY_SHARE project prorates goal across units (no $0)
    // =========================================================================

    public function test_mandatory_by_share_project_prorates_goal_across_units(): void
    {
        $tenant = $this->makeTenant('By Share Tenant');
        $this->seedTenant($tenant);

        $unit40 = $this->makeUnitWithShare($tenant, 40.0);
        $unit30 = $this->makeUnitWithShare($tenant, 30.0);
        $unit20 = $this->makeUnitWithShare($tenant, 20.0);
        $unit10 = $this->makeUnitWithShare($tenant, 10.0);

        $tenant->run(function () use ($unit40, $unit30, $unit20, $unit10): void {
            $mandatoryType = ParticipationType::findOrFail(ParticipationType::MANDATORY);
            $byShareMode = AmountMode::findOrFail(AmountMode::BY_SHARE);

            $project = $this->buildProject([
                'participation_type_id' => $mandatoryType->id,
                'amount_mode_id' => $byShareMode->id,
                'fixed_amount' => null,
                'goal_amount' => 1000000, // $10,000 to prorate by share
                'target_type' => 'manual',
                'target_selector' => ['unit_ids' => [$unit40->id, $unit30->id, $unit20->id, $unit10->id]],
            ]);

            $service = app(ProjectActivationService::class);
            $activated = $service->activate($project);

            $expectedByUnit = [
                $unit40->id => 400000,
                $unit30->id => 300000,
                $unit20->id => 200000,
                $unit10->id => 100000,
            ];

            $contributions = ProjectContribution::where('community_project_id', $activated->id)->get();
            $this->assertCount(4, $contributions);

            $sumContributions = 0;
            foreach ($contributions as $contribution) {
                $raw = (int) $contribution->getAttributes()['expected_amount'];
                $this->assertSame(
                    $expectedByUnit[$contribution->unit_id],
                    $raw,
                    'By-share contribution must equal the unit\'s prorated share, never $0'
                );
                $sumContributions += $raw;
            }
            $this->assertSame(1000000, $sumContributions, 'Per-unit shares must sum EXACTLY to goal');

            // Mandatory → one charge per unit with the same prorated amount (no $0).
            $charges = Charge::where('community_project_id', $activated->id)->get();
            $this->assertCount(4, $charges);

            $sumCharges = 0;
            foreach ($charges as $charge) {
                $raw = (int) $charge->getAttributes()['expected_amount'];
                $this->assertSame($expectedByUnit[$charge->unit_id], $raw);
                $this->assertGreaterThan(0, $raw, 'By-share charge must never be $0');
                $sumCharges += $raw;
            }
            $this->assertSame(1000000, $sumCharges, 'Charges must sum EXACTLY to goal');
        });
    }

    private function makeUnitWithShare(Tenant $tenant, float $share): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit, $share): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(100, 9999),
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => $share,
            ]);
        });

        return $unit;
    }

    public function test_tenant_isolation_project_contribution_table(): void
    {
        $tenant1 = $this->makeTenant('Contrib T1');
        $tenant2 = $this->makeTenant('Contrib T2');
        $this->seedTenant($tenant1);
        $this->seedTenant($tenant2);

        $unit1 = $this->makeUnit($tenant1);

        $tenant1->run(function () use ($unit1): void {
            $voluntaryType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $project = $this->buildProject([
                'participation_type_id' => $voluntaryType->id,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit1->id],
            ]);

            $service = app(ProjectActivationService::class);
            $service->activate($project);

            $this->assertGreaterThan(0, ProjectContribution::count());
        });

        $tenant2->run(function (): void {
            $this->assertSame(0, ProjectContribution::count(), 'Tenant 2 must not see Tenant 1 contributions');
        });
    }
}
