<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Reports\Models\ScheduledReport;
use Modules\Reports\Services\ReportService;
use Modules\Tenant\Models\Tenant;
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
 * Integration tests for Module 11 — Reportes y Exportación.
 */
final class ReportsModuleTest extends TestCase
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
                'number' => (string) mt_rand(1, 999),
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        return $unit;
    }

    /**
     * Build a minimal ChargeConcept inside the tenant context (already initialized).
     */
    private function makeConceptInsideTenant(string $code = 'MANT001'): ChargePolicy
    {
        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

        $concept = ChargeConcept::create([
            'code' => $code,
            'name' => 'Mantenimiento',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        return ChargePolicy::create([
            'charge_concept_id' => $concept->id,
            'name' => 'Política mantenimiento',
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'periodicity_id' => Periodicity::MONTHLY,
            'fixed_amount' => 70000,
            'valid_from' => '2026-01-01',
            'is_active' => true,
            'grace_days' => 0,
        ]);
    }

    /**
     * Build a minimal ChargeBatch inside the tenant context (already initialized).
     */
    private function makeBatchInsideTenant(ChargePolicy $policy, int $month = 1): ChargeBatch
    {
        $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);

        return ChargeBatch::create([
            'charge_policy_id' => $policy->id,
            'title' => "Cobro Test {$month}",
            'year' => 2026,
            'month' => $month,
            'target_type' => 'all',
            'issued_at' => '2026-03-01',
            'due_date' => '2026-03-15',
            'charge_batch_status_id' => $draftStatus->id,
            'progress_pct' => 0,
        ]);
    }

    /**
     * Build a minimal BankAccount inside the tenant context (already initialized).
     */
    private function makeBankAccountInsideTenant(string $name = 'Caja'): BankAccount
    {
        $accountType = BankAccountType::findOrFail(BankAccountType::CASH);
        $accountPurpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));

        return BankAccount::create([
            'name' => $name,
            'bank_account_type_id' => $accountType->id,
            'bank_account_purpose_id' => $accountPurpose->id,
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Delinquency
    // =========================================================================

    public function test_delinquency_lists_units_with_overdue_charges(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $overdueStatus = ChargeStatus::findOrFail(ChargeStatus::OVERDUE);
            $concept = $this->makeConceptInsideTenant('MANT001');
            $batch = $this->makeBatchInsideTenant($concept, 1);

            Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'paid_amount' => 0,
                'issued_at' => now()->subDays(45),
                'due_date' => now()->subDays(30),
                'charge_status_id' => $overdueStatus->id,
                'is_cancelled' => false,
            ]);

            $service = app(ReportService::class);
            $result = $service->delinquency();

            $this->assertSame(1, $result['unit_count'], 'One unit should be delinquent');
            $this->assertSame(70000, $result['total_overdue_amount'], 'Total overdue should be 70000 minor units');
            $this->assertSame(1, $result['units']->count());
            $this->assertSame($unit->id, $result['units']->first()['unit_id']);
        });
    }

    public function test_delinquency_excludes_cancelled_charges(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $overdueStatus = ChargeStatus::findOrFail(ChargeStatus::OVERDUE);
            $concept = $this->makeConceptInsideTenant('MANT002');
            $batch = $this->makeBatchInsideTenant($concept, 2);

            Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'paid_amount' => 0,
                'issued_at' => now()->subDays(45),
                'due_date' => now()->subDays(30),
                'charge_status_id' => $overdueStatus->id,
                'is_cancelled' => true, // ← cancelled, must be excluded
            ]);

            $service = app(ReportService::class);
            $result = $service->delinquency();

            $this->assertSame(0, $result['unit_count'], 'Cancelled charges must not appear in delinquency');
        });
    }

    /**
     * R-MAPI-10 / R-MAPI-14: the dedicated `reports/delinquency` endpoint wraps
     * money as a Money object, while `generate(format=json)` on the SAME key
     * stays raw (it feeds the PDF/CSV/XLSX export pipeline, untouched by this
     * change) — both must agree on the underlying minor-unit amount, and the
     * export formats must keep working end-to-end.
     */
    public function test_delinquency_generate_json_stays_raw_while_dedicated_endpoint_wraps_money(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);
        $admin = $this->makeUser($tenant);
        $token = $admin->createToken('report-parity', ['*'])->plainTextToken;

        $tenant->run(function () use ($unit): void {
            $overdueStatus = ChargeStatus::findOrFail(ChargeStatus::OVERDUE);
            $concept = $this->makeConceptInsideTenant('MANT-PARITY');
            $batch = $this->makeBatchInsideTenant($concept, 1);

            Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'paid_amount' => 0,
                'issued_at' => now()->subDays(45),
                'due_date' => now()->subDays(30),
                'charge_status_id' => $overdueStatus->id,
                'is_cancelled' => false,
            ]);
        });

        // Dedicated endpoint (ReportController::delinquency) wraps money as a Money object.
        $wrapped = $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/delinquency')
            ->assertStatus(200)
            ->json();

        $this->assertIsArray($wrapped['total_overdue_amount']);
        $this->assertSame('70000', $wrapped['total_overdue_amount']['amount']);
        $this->assertSame('MXN', $wrapped['total_overdue_amount']['currency']);

        // generate(format=json) on the SAME key stays RAW.
        $raw = $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/delinquency/generate?format=json', [])
            ->assertStatus(200)
            ->json();

        $this->assertIsInt($raw['data']['total_overdue_amount']);
        $this->assertSame(70000, $raw['data']['total_overdue_amount']);

        // Same underlying amount either way — only the serialization shape differs.
        $this->assertSame((int) $wrapped['total_overdue_amount']['amount'], $raw['data']['total_overdue_amount']);

        // The PDF export pipeline (ReportFormatter, untouched by this change) still works.
        $pdf = $this->tenantRequest($token, $tenant)
            ->post('/api/reports/delinquency/generate?format=pdf', []);
        $pdf->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_delinquency_outstanding_honors_discount_and_surcharge(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $overdueStatus = ChargeStatus::findOrFail(ChargeStatus::OVERDUE);
            $concept = $this->makeConceptInsideTenant('MANT-DISC');
            $batch = $this->makeBatchInsideTenant($concept, 3);

            Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => 70000,
                'discount_amount' => 10000,
                'surcharge_amount' => 5000,
                'paid_amount' => 20000,
                'issued_at' => now()->subDays(45),
                'due_date' => now()->subDays(30),
                'charge_status_id' => $overdueStatus->id,
                'is_cancelled' => false,
            ]);

            $service = app(ReportService::class);
            $result = $service->delinquency();

            // max(0, max(0, 70000 − 10000) + 5000 − 20000) = 45000
            $this->assertSame(45000, $result['total_overdue_amount'], 'Outstanding must honor discount and surcharge');

            $row = $result['units']->first();
            $this->assertSame($unit->id, $row['unit_id']);
            $this->assertNotEmpty($row['unit_full_identifier'], 'Report rows must expose the human-readable unit identifier');
        });
    }

    // =========================================================================
    // Income statement
    // =========================================================================

    public function test_income_statement_nets_payments_minus_expenses_for_period(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $confirmedStatus = PaymentStatus::findOrFail(PaymentStatus::CONFIRMED);
            $paymentMethod = PaymentMethod::first();
            $bankAccount = $this->makeBankAccountInsideTenant();
            $paidExpenseStatus = ExpenseStatus::findOrFail(ExpenseStatus::PAID);
            $expenseCategory = ExpenseCategory::whereNull('parent_id')->first();

            // Two confirmed payments: $700 + $500 = $1200
            Payment::create([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-15',
                'total_amount' => 70000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
                'payment_status_id' => $confirmedStatus->id,
                'receipt_number' => 'R-2026-000001',
            ]);

            Payment::create([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-20',
                'total_amount' => 50000,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
                'payment_status_id' => $confirmedStatus->id,
                'receipt_number' => 'R-2026-000002',
            ]);

            // One expense: $300
            Expense::create([
                'concept' => 'Limpieza',
                'paid_at' => '2026-03-10',
                'amount' => 30000,
                'expense_category_id' => $expenseCategory->id,
                'expense_status_id' => $paidExpenseStatus->id,
                'beneficiary_type_id' => BeneficiaryType::first()->id,
                'bank_account_id' => $bankAccount->id,
                'payment_method_id' => $paymentMethod->id,
                'year' => 2026,
                'month' => 3,
            ]);

            $service = app(ReportService::class);
            $result = $service->incomeStatement('2026-03-01', '2026-03-31');

            $this->assertSame(120000, $result['total_income'], 'Total income should be 120000 minor units');
            $this->assertSame(30000, $result['total_expenses'], 'Total expenses should be 30000 minor units');
            $this->assertSame(90000, $result['net'], 'Net should be 120000 - 30000 = 90000');
        });
    }

    public function test_income_statement_excludes_payments_outside_period(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $unit = $this->makeUnit($tenant);

        $tenant->run(function () use ($unit): void {
            $confirmedStatus = PaymentStatus::findOrFail(PaymentStatus::CONFIRMED);
            $paymentMethod = PaymentMethod::first();
            $bankAccount = $this->makeBankAccountInsideTenant();

            Payment::create([
                'unit_id' => $unit->id,
                'paid_at' => '2026-02-01', // ← outside March period
                'total_amount' => 99999,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $bankAccount->id,
                'payment_status_id' => $confirmedStatus->id,
                'receipt_number' => 'R-2026-000010',
            ]);

            $service = app(ReportService::class);
            $result = $service->incomeStatement('2026-03-01', '2026-03-31');

            $this->assertSame(0, $result['total_income'], 'Payment in February must not count in March income');
        });
    }

    // =========================================================================
    // Account balances
    // =========================================================================

    public function test_account_balances_returns_active_accounts(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $accountType = BankAccountType::findOrFail(BankAccountType::BANK);
            $accountPurpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));

            BankAccount::create([
                'name' => 'HSBC Principal',
                'bank_account_type_id' => $accountType->id,
                'bank_account_purpose_id' => $accountPurpose->id,
                'currency' => 'MXN',
                'initial_balance' => 500000,
                'is_active' => true,
                'is_primary' => true,
            ]);

            BankAccount::create([
                'name' => 'Cuenta inactiva',
                'bank_account_type_id' => $accountType->id,
                'bank_account_purpose_id' => $accountPurpose->id,
                'currency' => 'MXN',
                'initial_balance' => 100000,
                'is_active' => false, // should not appear
            ]);

            $service = app(ReportService::class);
            $balances = $service->accountBalances();

            $this->assertSame(1, $balances->count(), 'Only active accounts should be returned');
            $this->assertSame('HSBC Principal', $balances->first()->name);
        });
    }

    // =========================================================================
    // Collection progress
    // =========================================================================

    public function test_collection_progress_returns_correct_stats(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        // One unit per charge: charge has a unique (charge_batch_id, unit_id) index.
        $unitA = $this->makeUnit($tenant);
        $unitB = $this->makeUnit($tenant);
        $unitC = $this->makeUnit($tenant);

        $tenant->run(function () use ($unitA, $unitB, $unitC): void {
            $completedStatus = ChargeStatus::findOrFail(ChargeStatus::COMPLETED);
            $overdueStatus = ChargeStatus::findOrFail(ChargeStatus::OVERDUE);
            $concept = $this->makeConceptInsideTenant('MANT003');
            $batch = $this->makeBatchInsideTenant($concept, 3);

            // 2 completed + 1 overdue
            Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unitA->id,
                'expected_amount' => 70000,
                'paid_amount' => 70000,
                'issued_at' => now()->subDays(20),
                'due_date' => now()->subDays(5),
                'charge_status_id' => $completedStatus->id,
                'is_cancelled' => false,
            ]);

            Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unitB->id,
                'expected_amount' => 70000,
                'paid_amount' => 70000,
                'issued_at' => now()->subDays(20),
                'due_date' => now()->subDays(5),
                'charge_status_id' => $completedStatus->id,
                'is_cancelled' => false,
            ]);

            Charge::create([
                'charge_batch_id' => $batch->id,
                'charge_concept_id' => $concept->charge_concept_id,
                'charge_policy_id' => $concept->id,
                'unit_id' => $unitC->id,
                'expected_amount' => 70000,
                'paid_amount' => 0,
                'issued_at' => now()->subDays(50),
                'due_date' => now()->subDays(35),
                'charge_status_id' => $overdueStatus->id,
                'is_cancelled' => false,
            ]);

            $service = app(ReportService::class);
            $result = $service->collectionProgress($batch->id);

            $this->assertSame(3, $result['total_charges']);
            $this->assertSame(2, $result['paid_count']);
            $this->assertSame(1, $result['overdue_count']);
            $this->assertEqualsWithDelta(66.67, $result['progress_pct'], 0.1);
        });
    }

    // =========================================================================
    // ScheduledReport CRUD + next_run computation
    // =========================================================================

    public function test_scheduled_report_can_be_created_and_next_run_computed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $report = ScheduledReport::create([
                'report_key' => ScheduledReport::KEY_DELINQUENCY,
                'config' => ['period' => 'monthly'],
                'frequency' => ScheduledReport::FREQUENCY_MONTHLY,
                'format' => 'pdf',
                'recipients' => ['admin@test.com'],
                'is_active' => true,
            ]);

            $this->assertNotNull($report->id);
            $this->assertSame('delinquency', $report->report_key);

            // Compute next run
            $report->computeNextRun();
            $this->assertNotNull($report->next_run_at, 'Monthly frequency must produce a next_run_at');
        });
    }

    public function test_scheduled_report_frequencies_produce_next_run_in_future(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $daily = (new ScheduledReport([
                'report_key' => ScheduledReport::KEY_DELINQUENCY,
                'frequency' => ScheduledReport::FREQUENCY_DAILY,
                'format' => 'pdf',
            ]))->computeNextRun();

            $weekly = (new ScheduledReport([
                'report_key' => ScheduledReport::KEY_DELINQUENCY,
                'frequency' => ScheduledReport::FREQUENCY_WEEKLY,
                'format' => 'pdf',
            ]))->computeNextRun();

            $monthly = (new ScheduledReport([
                'report_key' => ScheduledReport::KEY_DELINQUENCY,
                'frequency' => ScheduledReport::FREQUENCY_MONTHLY,
                'format' => 'pdf',
            ]))->computeNextRun();

            $this->assertNotNull($daily->next_run_at);
            $this->assertNotNull($weekly->next_run_at);
            $this->assertNotNull($monthly->next_run_at);

            // All next_run values should be in the future
            $this->assertTrue($daily->next_run_at->gt(now()), 'Daily next_run_at should be in the future');
            $this->assertTrue($weekly->next_run_at->gt(now()), 'Weekly next_run_at should be in the future');
            $this->assertTrue($monthly->next_run_at->gt(now()), 'Monthly next_run_at should be in the future');
        });
    }

    // =========================================================================
    // Tenant isolation for Reports
    // =========================================================================

    public function test_scheduled_report_from_tenant_a_not_visible_in_tenant_b(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');

        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            ScheduledReport::create([
                'report_key' => ScheduledReport::KEY_DELINQUENCY,
                'frequency' => ScheduledReport::FREQUENCY_MONTHLY,
                'format' => 'pdf',
                'is_active' => true,
            ]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, ScheduledReport::count(), 'Scheduled report from Tenant A must not be visible in Tenant B');
        });
    }
}
