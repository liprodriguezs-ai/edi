<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
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
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Models\ProjectContributionStatus;
use Modules\Providers\Models\Provider;
use Modules\Providers\Models\ProviderType;
use Modules\Reports\Services\ReportRegistry;
use Modules\Reports\Services\ReportService;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Models\ExpenseStatus;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Tests for the 14 new Kivo7 reports (spec §15.3) plus the 5-minute cache.
 */
final class ReportsCatalogTest extends TestCase
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

    private function makeUnitInside(): Unit
    {
        $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
        $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

        return Unit::create([
            'unit_type_id' => $unitType->id,
            'number' => (string) mt_rand(1, 99999),
            'unit_status_id' => $unitStatus->id,
        ]);
    }

    private function makeBankAccountInside(): BankAccount
    {
        return BankAccount::create([
            'name' => 'Caja',
            'bank_account_type_id' => BankAccountType::findOrFail(BankAccountType::CASH)->id,
            'bank_account_purpose_id' => BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'))->id,
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_active' => true,
        ]);
    }

    private function makeConfirmedPayment(Unit $unit, BankAccount $account, int $amount, string $paidAt, string $receipt): Payment
    {
        return Payment::create([
            'unit_id' => $unit->id,
            'paid_at' => $paidAt,
            'total_amount' => $amount,
            'payment_method_id' => PaymentMethod::CASH,
            'bank_account_id' => $account->id,
            'payment_status_id' => PaymentStatus::CONFIRMED,
            'receipt_number' => $receipt,
        ]);
    }

    private function makePaidExpense(BankAccount $account, int $amount, string $paidAt, ?string $providerId = null): Expense
    {
        return Expense::create([
            'concept' => 'Gasto '.Str::random(4),
            'paid_at' => $paidAt,
            'amount' => $amount,
            'expense_category_id' => ExpenseCategory::whereNull('parent_id')->first()->id,
            'expense_status_id' => ExpenseStatus::PAID,
            'beneficiary_type_id' => BeneficiaryType::first()->id,
            'bank_account_id' => $account->id,
            'payment_method_id' => PaymentMethod::CASH,
            'provider_id' => $providerId,
            'year' => (int) substr($paidAt, 0, 4),
            'month' => (int) substr($paidAt, 5, 2),
        ]);
    }

    // =========================================================================
    // FINANCIAL
    // =========================================================================

    public function test_cash_flow_buckets_income_and_expenses_with_running_balance(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = $this->makeUnitInside();
            $account = $this->makeBankAccountInside();

            $this->makeConfirmedPayment($unit, $account, 100000, '2026-01-10', 'R-2026-100001');
            $this->makeConfirmedPayment($unit, $account, 50000, '2026-02-10', 'R-2026-100002');
            $this->makePaidExpense($account, 30000, '2026-01-15');

            $result = app(ReportService::class)->cashFlow('2026-01-01', '2026-02-28');

            $this->assertSame(150000, $result['total_income']);
            $this->assertSame(30000, $result['total_expenses']);
            $this->assertSame(120000, $result['net']);
            $this->assertSame(2, $result['buckets']->count());

            $jan = $result['buckets']->firstWhere('period', '2026-01');
            $feb = $result['buckets']->firstWhere('period', '2026-02');
            $this->assertSame(70000, $jan['net']);
            $this->assertSame(70000, $jan['running_balance']);
            $this->assertSame(120000, $feb['running_balance']);
        });
    }

    public function test_expenses_by_category_groups_non_void_expenses(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $account = $this->makeBankAccountInside();
            $this->makePaidExpense($account, 30000, '2026-03-05');
            $this->makePaidExpense($account, 20000, '2026-03-10');

            // Voided expense must be excluded
            Expense::create([
                'concept' => 'Anulado',
                'paid_at' => '2026-03-12',
                'amount' => 99999,
                'expense_category_id' => ExpenseCategory::whereNull('parent_id')->first()->id,
                'expense_status_id' => ExpenseStatus::VOIDED,
                'beneficiary_type_id' => BeneficiaryType::first()->id,
                'bank_account_id' => $account->id,
                'payment_method_id' => PaymentMethod::CASH,
                'year' => 2026,
                'month' => 3,
            ]);

            $result = app(ReportService::class)->expensesByCategory('2026-03-01', '2026-03-31');

            $this->assertSame(50000, $result['total_expenses'], 'Voided expense must be excluded');
            $this->assertSame(1, $result['categories']->count());
            $this->assertSame(2, $result['categories']->first()['expense_count']);
        });
    }

    public function test_annual_fiscal_summarizes_income_and_expenses_for_year(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = $this->makeUnitInside();
            $account = $this->makeBankAccountInside();

            $this->makeConfirmedPayment($unit, $account, 200000, '2026-05-01', 'R-2026-200001');
            $this->makePaidExpense($account, 80000, '2026-06-01');

            $result = app(ReportService::class)->annualFiscal(2026);

            $this->assertSame(2026, $result['year']);
            $this->assertSame(200000, $result['total_income']);
            $this->assertSame(80000, $result['total_expenses']);
            $this->assertSame(120000, $result['net']);
        });
    }

    // =========================================================================
    // COLLECTIONS
    // =========================================================================

    public function test_unit_statement_returns_charges_payments_and_credit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = $this->makeUnitInside();
            $account = $this->makeBankAccountInside();
            $this->seedChargeFor($unit, 70000, 30000, ChargeStatus::PARTIAL);
            $this->makeConfirmedPayment($unit, $account, 30000, '2026-03-15', 'R-2026-300001');
            CreditBalance::create(['unit_id' => $unit->id, 'amount' => 5000]);

            $result = app(ReportService::class)->unitStatement($unit->id);

            $this->assertNotNull($result);
            $this->assertSame($unit->id, $result['unit_id']);
            $this->assertSame(70000, $result['total_expected']);
            $this->assertSame(30000, $result['total_paid']);
            $this->assertSame(40000, $result['total_outstanding']);
            $this->assertSame(5000, $result['credit_balance']);
            $this->assertSame(1, $result['charges']->count());
            $this->assertSame(1, $result['payments']->count());
        });
    }

    public function test_unit_statement_returns_null_for_unknown_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertNull(app(ReportService::class)->unitStatement((string) Str::uuid()));
        });
    }

    public function test_global_statement_summarizes_all_units(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unitA = $this->makeUnitInside();
            $unitB = $this->makeUnitInside();
            $this->seedChargeFor($unitA, 70000, 70000, ChargeStatus::COMPLETED);
            $this->seedChargeFor($unitB, 70000, 0, ChargeStatus::OVERDUE);

            $result = app(ReportService::class)->globalStatement();

            $this->assertSame(2, $result['unit_count']);
            $this->assertSame(140000, $result['total_expected']);
            $this->assertSame(70000, $result['total_paid']);
            $this->assertSame(70000, $result['total_outstanding']);
        });
    }

    public function test_payment_history_lists_payments_in_period(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = $this->makeUnitInside();
            $account = $this->makeBankAccountInside();
            $this->makeConfirmedPayment($unit, $account, 40000, '2026-04-10', 'R-2026-400001');
            $this->makeConfirmedPayment($unit, $account, 60000, '2026-02-10', 'R-2026-400002');

            $result = app(ReportService::class)->paymentHistory('2026-04-01', '2026-04-30');

            $this->assertSame(1, $result['payment_count']);
            $this->assertSame(40000, $result['total_amount']);
        });
    }

    public function test_credit_balances_lists_only_positive_balances(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unitA = $this->makeUnitInside();
            $unitB = $this->makeUnitInside();
            CreditBalance::create(['unit_id' => $unitA->id, 'amount' => 15000]);
            CreditBalance::create(['unit_id' => $unitB->id, 'amount' => 0]);

            $result = app(ReportService::class)->creditBalances();

            $this->assertSame(1, $result['unit_count']);
            $this->assertSame(15000, $result['total_credit']);
        });
    }

    // =========================================================================
    // OPERATIONS
    // =========================================================================

    public function test_provider_history_groups_expenses_by_provider(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $account = $this->makeBankAccountInside();
            $provider = Provider::create([
                'name' => 'Jardinería SA',
                'provider_type_id' => Catalog::id(ProviderType::class, 'Persona moral'),
                'is_active' => true,
            ]);

            $this->makePaidExpense($account, 25000, '2026-03-05', $provider->id);
            $this->makePaidExpense($account, 35000, '2026-03-20', $provider->id);

            $result = app(ReportService::class)->providerHistory('2026-03-01', '2026-03-31');

            $this->assertSame(60000, $result['total_paid']);
            $this->assertSame(1, $result['providers']->count());
            $this->assertSame(2, $result['providers']->first()['payment_count']);
        });
    }

    public function test_project_progress_computes_funding_metrics(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = $this->makeUnitInside();
            $project = CommunityProject::create([
                'title' => 'Caseta nueva',
                'community_project_category_id' => Catalog::id(CommunityProjectCategory::class, 'Obra'),
                'community_project_status_id' => CommunityProjectStatus::ACTIVE,
                'participation_type_id' => ParticipationType::first()->id,
                'amount_mode_id' => AmountMode::findOrFail(AmountMode::FIXED_REQUIRED)->id,
                'goal_amount' => 100000,
                'target_type' => 'all',
            ]);

            ProjectContribution::create([
                'community_project_id' => $project->id,
                'unit_id' => $unit->id,
                'expected_amount' => 50000,
                'contributed_amount' => 40000,
                'project_contribution_status_id' => ProjectContributionStatus::PARTIAL,
            ]);

            $result = app(ReportService::class)->projectProgress();

            $this->assertSame(1, $result['project_count']);
            $row = $result['projects']->first();
            $this->assertSame(100000, $row['goal_amount']);
            $this->assertSame(40000, $row['raised_amount']);
            $this->assertSame(60000, $row['available_amount']);
            $this->assertEqualsWithDelta(40.0, $row['progress_pct'], 0.01);
            $this->assertSame(1, $row['contribution_count']);
        });
    }

    // =========================================================================
    // ADMINISTRATIVE
    // =========================================================================

    public function test_resident_directory_lists_active_residents(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $user = User::create([
            'name' => 'Juan',
            'last_name' => 'Pérez',
            'email' => Str::random(8).'@test.com',
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);

        $tenant->run(function () use ($user): void {
            $unit = $this->makeUnitInside();
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'start_date' => '2026-01-01',
            ]);

            $result = app(ReportService::class)->residentDirectory();

            $this->assertSame(1, $result['resident_count']);
            $this->assertStringContainsString('Juan', $result['residents']->first()['name']);
        });
    }

    public function test_active_board_lists_current_period_members(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $user = User::create([
            'name' => 'Ana',
            'last_name' => 'López',
            'email' => Str::random(8).'@test.com',
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);

        $tenant->run(function () use ($user): void {
            $period = BoardPeriod::create([
                'name' => 'Mesa 2026',
                'start_date' => '2026-01-01',
                'end_date' => '2027-01-01',
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            BoardMember::create([
                'board_period_id' => $period->id,
                'user_id' => $user->id,
                'board_role_id' => BoardRole::PRESIDENT,
            ]);

            $result = app(ReportService::class)->activeBoard();

            $this->assertSame('Mesa 2026', $result['period']);
            $this->assertSame(1, $result['member_count']);
        });
    }

    public function test_announcements_sent_lists_published_in_period(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            Announcement::create([
                'title' => 'Aviso importante',
                'announcement_type_id' => AnnouncementType::first()->id,
                'announcement_priority_id' => AnnouncementPriority::first()->id,
                'announcement_status_id' => AnnouncementStatus::PUBLISHED,
                'content' => 'Contenido',
                'target_type' => 'all',
                'channel_push' => true,
                'channel_email' => true,
                'publish_at' => '2026-03-10 10:00:00',
            ]);

            // Draft must be excluded
            Announcement::create([
                'title' => 'Borrador',
                'announcement_type_id' => AnnouncementType::first()->id,
                'announcement_priority_id' => AnnouncementPriority::first()->id,
                'announcement_status_id' => AnnouncementStatus::DRAFT,
                'content' => 'Draft',
                'target_type' => 'all',
                'publish_at' => '2026-03-11 10:00:00',
            ]);

            $result = app(ReportService::class)->announcementsSent('2026-03-01', '2026-03-31');

            $this->assertSame(1, $result['announcement_count']);
            $this->assertStringContainsString('Push', $result['announcements']->first()['channels']);
        });
    }

    // =========================================================================
    // 5-minute cache (§15.6 r3)
    // =========================================================================

    public function test_cached_build_returns_identical_data_within_window(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = $this->makeUnitInside();
            CreditBalance::create(['unit_id' => $unit->id, 'amount' => 12345]);

            $registry = app(ReportRegistry::class);

            $first = $registry->cachedBuild('credit_balances', []);

            // Mutate the underlying data — cached call must NOT reflect the change.
            CreditBalance::create(['unit_id' => $this->makeUnitInside()->id, 'amount' => 99999]);

            $second = $registry->cachedBuild('credit_balances', []);

            $this->assertSame($first['total_credit'], $second['total_credit'], 'Cached report must be served within the 5-minute window');
            $this->assertSame(12345, $second['total_credit']);

            Cache::flush();
            $third = $registry->cachedBuild('credit_balances', []);
            $this->assertSame(12345 + 99999, $third['total_credit'], 'After cache flush, fresh data is recomputed');
        });
    }

    // =========================================================================
    // Shared seed helper
    // =========================================================================

    private function seedChargeFor(Unit $unit, int $expected, int $paid, string $statusId): Charge
    {
        $concept = ChargeConcept::create([
            'code' => 'C-'.Str::random(5),
            'name' => 'Mantenimiento',
            'charge_concept_category_id' => ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'))->id,
            'is_active' => true,
        ]);

        $policy = ChargePolicy::create([
            'charge_concept_id' => $concept->id,
            'name' => 'Política mantenimiento',
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'periodicity_id' => Periodicity::MONTHLY,
            'fixed_amount' => $expected,
            'valid_from' => '2026-01-01',
            'is_active' => true,
            'grace_days' => 0,
        ]);

        $batch = ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => 'Batch '.Str::random(4),
            'year' => 2026,
            'month' => 3,
            'target_type' => 'all',
            'issued_at' => '2026-03-01',
            'due_date' => '2026-03-15',
            'charge_batch_status_id' => ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT)->id,
            'progress_pct' => 0,
        ]);

        return Charge::create([
            'charge_batch_id' => $batch->id,
            'charge_concept_id' => $concept->id,
            'unit_id' => $unit->id,
            'expected_amount' => $expected,
            'paid_amount' => $paid,
            'issued_at' => '2026-03-01',
            'due_date' => '2026-03-15',
            'charge_status_id' => $statusId,
            'is_cancelled' => false,
        ]);
    }
}
