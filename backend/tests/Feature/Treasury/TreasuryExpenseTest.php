<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use Cknow\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Providers\Models\Provider;
use Modules\Providers\Models\ProviderType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Models\ExpenseStatus;
use Modules\Treasury\Services\ExpenseService;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Treasury Expenses (Module 5-rest).
 *
 * Covers:
 *  - ExpenseStatus + BeneficiaryType catalogs seeded per tenant
 *  - register() decrements bank_account.current_balance
 *  - void() restores bank_account.current_balance + status=voided
 *  - Expense is never deleted (status=voided, not physically removed)
 *  - Amount money round-trip (integer storage + MoneyIntegerCast)
 *  - Duplicate detection fires (same provider + period + similar amount within 7 days)
 *  - Duplicate detection can be forced
 *  - BeneficiaryType: employee vs provider vs other
 *  - Tenant isolation
 *  - Cross-module balance: bank_account.current_balance = initial + confirmed_payment − expense
 *  - Community project id stored without FK constraint
 */
final class TreasuryExpenseTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeBankAccount(Tenant $tenant, int $initialBalance = 0): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $initialBalance): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => $initialBalance,
            ]);
        });

        return $account;
    }

    private function makeProvider(Tenant $tenant): Provider
    {
        $provider = null;
        $tenant->run(function () use (&$provider): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            $provider = Provider::create([
                'provider_type_id' => $type->id,
                'name' => 'Miguel Armando Flores',
                'is_recurring' => true,
                'frequency' => 'monthly',
                'estimated_amount' => 120000,
                'is_active' => true,
            ]);
        });

        return $provider;
    }

    /**
     * Build minimal valid expense data inside the tenant context.
     * Must be called INSIDE a $tenant->run() closure.
     */
    private function buildExpenseData(BankAccount $account, array $overrides = []): array
    {
        $category = ExpenseCategory::first();
        $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::OTHER);
        $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

        return array_merge([
            'concept' => 'Pago de prueba',
            'paid_at' => now()->toDateString(),
            'amount' => 100000, // $1,000.00
            'expense_category_id' => $category->id,
            'beneficiary_type_id' => $beneficiaryType->id,
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'payment_method_id' => $paymentMethod->id,
            'bank_account_id' => $account->id,
        ], $overrides);
    }

    // =========================================================================
    // A: Catalog seeding
    // =========================================================================

    public function test_expense_status_catalog_seeded_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(2, ExpenseStatus::count());

            $paid = ExpenseStatus::findOrFail(ExpenseStatus::PAID);
            $this->assertSame('Pagado', $paid->name);

            $voided = ExpenseStatus::findOrFail(ExpenseStatus::VOIDED);
            $this->assertSame('Anulado', $voided->name);
        });
    }

    public function test_beneficiary_type_catalog_seeded_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(3, BeneficiaryType::count());

            $employee = BeneficiaryType::findOrFail(BeneficiaryType::EMPLOYEE);
            $this->assertSame('Empleado', $employee->name);

            $provider = BeneficiaryType::findOrFail(BeneficiaryType::PROVIDER);
            $this->assertSame('Proveedor', $provider->name);

            $other = BeneficiaryType::findOrFail(BeneficiaryType::OTHER);
            $this->assertSame('Otro', $other->name);
        });
    }

    // =========================================================================
    // B: Register expense — balance decrement
    // =========================================================================

    public function test_register_expense_decrements_bank_account_balance(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 500000); // $5,000 initial

        $tenant->run(function () use ($account): void {
            $data = $this->buildExpenseData($account, ['amount' => 100000]);
            $service = app(ExpenseService::class);
            $result = $service->register($data);

            $this->assertFalse($result['duplicate']);
            $this->assertNotNull($result['expense']);

            // Balance must be decremented by 100000.
            $rawBalance = DB::table('bank_account')->where('id', $account->id)->value('current_balance');
            $this->assertSame(400000, (int) $rawBalance, 'Balance should be 500000 - 100000 = 400000.');
        });
    }

    // =========================================================================
    // C: Void — balance restore
    // =========================================================================

    public function test_void_expense_restores_bank_account_balance(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 500000);

        $tenant->run(function () use ($account): void {
            $data = $this->buildExpenseData($account, ['amount' => 150000]);
            $service = app(ExpenseService::class);
            ['expense' => $expense] = $service->register($data);

            // After register: 500000 - 150000 = 350000.
            $rawBefore = (int) DB::table('bank_account')->where('id', $account->id)->value('current_balance');
            $this->assertSame(350000, $rawBefore);

            $service->void($expense, 'Registrado por error');

            // After void: 350000 + 150000 = 500000 (restored).
            $rawAfter = (int) DB::table('bank_account')->where('id', $account->id)->value('current_balance');
            $this->assertSame(500000, $rawAfter, 'Balance should be restored to 500000 after void.');
        });
    }

    public function test_voided_expense_is_not_deleted_but_keeps_history(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 500000);

        $tenant->run(function () use ($account): void {
            $data = $this->buildExpenseData($account);
            $service = app(ExpenseService::class);
            ['expense' => $expense] = $service->register($data);

            $expenseId = $expense->id;
            $service->void($expense, 'Motivo de anulación');

            // Expense still exists (not deleted).
            $found = Expense::find($expenseId);
            $this->assertNotNull($found, 'Voided expense must still exist in the database.');

            // Status is voided.
            $voidedStatus = ExpenseStatus::findOrFail(ExpenseStatus::VOIDED);
            $this->assertSame($voidedStatus->id, $found->expense_status_id);
            $this->assertSame('Motivo de anulación', $found->void_reason);
        });
    }

    public function test_cannot_void_already_voided_expense(): void
    {
        // ValidationException (not InvalidArgumentException) so the HTTP layer
        // renders a clean 422 on a double void instead of a 500.
        $this->expectException(ValidationException::class);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 500000);

        $tenant->run(function () use ($account): void {
            $data = $this->buildExpenseData($account);
            $service = app(ExpenseService::class);
            ['expense' => $expense] = $service->register($data);

            $service->void($expense, 'Primera anulación');
            $expense->refresh();
            $service->void($expense, 'Segunda anulación'); // Should throw.
        });
    }

    // =========================================================================
    // D: Money round-trip
    // =========================================================================

    public function test_expense_amount_money_round_trip(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 10000000);

        $tenant->run(function () use ($account): void {
            $data = $this->buildExpenseData($account, ['amount' => 350000]);
            $service = app(ExpenseService::class);
            ['expense' => $expense] = $service->register($data);

            // Raw integer in DB.
            $rawAmount = DB::table('expense')->where('id', $expense->id)->value('amount');
            $this->assertSame(350000, (int) $rawAmount, 'amount must be stored as integer (minor units).');

            // MoneyIntegerCast wraps it.
            $fresh = Expense::find($expense->id);
            $this->assertInstanceOf(Money::class, $fresh->amount);
            $this->assertSame('350000', $fresh->amount->getAmount());
        });
    }

    // =========================================================================
    // E: Duplicate detection
    // =========================================================================

    public function test_duplicate_detection_fires_for_same_provider_period_amount(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 5000000);
        $provider = $this->makeProvider($tenant);

        $tenant->run(function () use ($account, $provider): void {
            $category = ExpenseCategory::first();
            $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::PROVIDER);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::TRANSFER);

            $baseData = [
                'concept' => 'Pago basura marzo',
                'paid_at' => now()->toDateString(),
                'amount' => 120000,
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryType->id,
                'provider_id' => $provider->id,
                'year' => now()->year,
                'month' => now()->month,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $account->id,
            ];

            $service = app(ExpenseService::class);

            // First: no duplicate.
            $result1 = $service->register($baseData);
            $this->assertFalse($result1['duplicate']);
            $this->assertNotNull($result1['expense']);

            // Second with same provider + period + similar amount: duplicate detected.
            $result2 = $service->register($baseData);
            $this->assertTrue($result2['duplicate'], 'Duplicate must be detected for same provider+period+amount.');
            $this->assertNull($result2['expense']);
        });
    }

    public function test_duplicate_detection_can_be_forced(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 5000000);
        $provider = $this->makeProvider($tenant);

        $tenant->run(function () use ($account, $provider): void {
            $category = ExpenseCategory::first();
            $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::PROVIDER);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $baseData = [
                'concept' => 'Pago basura',
                'paid_at' => now()->toDateString(),
                'amount' => 120000,
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryType->id,
                'provider_id' => $provider->id,
                'year' => now()->year,
                'month' => now()->month,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $account->id,
            ];

            $service = app(ExpenseService::class);
            $service->register($baseData); // First.

            // Force = true: duplicate detection bypassed.
            $result = $service->register($baseData, force: true);
            $this->assertFalse($result['duplicate'], 'Forced register must bypass duplicate detection.');
            $this->assertNotNull($result['expense']);
            $this->assertSame(2, Expense::count());
        });
    }

    // =========================================================================
    // F: Beneficiary types
    // =========================================================================

    public function test_expense_with_beneficiary_type_employee(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 5000000);

        $tenant->run(function () use ($account): void {
            $category = ExpenseCategory::first();
            $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::EMPLOYEE);
            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::TRANSFER);

            $data = [
                'concept' => '1ra quincena marzo',
                'paid_at' => now()->toDateString(),
                'amount' => 350000,
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryType->id,
                'year' => now()->year,
                'month' => now()->month,
                'fortnight' => 1,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $account->id,
            ];

            $service = app(ExpenseService::class);
            ['expense' => $expense] = $service->register($data);

            $expense->load('beneficiaryType');
            $this->assertSame('Empleado', $expense->beneficiaryType->name);
            $this->assertSame(1, $expense->fortnight);
        });
    }

    public function test_expense_with_beneficiary_type_other_and_text(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 5000000);

        $tenant->run(function () use ($account): void {
            $data = $this->buildExpenseData($account, [
                'beneficiary_text' => 'Ferretería La Paloma',
            ]);

            $service = app(ExpenseService::class);
            ['expense' => $expense] = $service->register($data);

            $this->assertSame('Ferretería La Paloma', $expense->beneficiary_text);
        });
    }

    // =========================================================================
    // G: Tenant isolation
    // =========================================================================

    public function test_expense_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $accountA = $this->makeBankAccount($tenantA, 5000000);

        $tenantA->run(function () use ($accountA): void {
            $data = $this->buildExpenseData($accountA);
            app(ExpenseService::class)->register($data);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, Expense::count(), 'Tenant B must not see Tenant A expenses.');
        });
    }

    // =========================================================================
    // H: Cross-module balance (payment + expense)
    // =========================================================================

    public function test_cross_module_balance_payment_then_expense(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($account): void {
            // 1. Make a unit and register a payment (income via Collections PaymentService).
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => '101',
                'unit_status_id' => $unitStatus->id,
                'ownership_share' => 1.5,
            ]);

            $paymentMethod = PaymentMethod::findOrFail(PaymentMethod::CASH);

            $paymentService = app(PaymentService::class, [
                'chargeStatusService' => app(ChargeStatusService::class),
                'chargeGenerationService' => app(ChargeGenerationService::class),
            ]);

            $paymentService->register([
                'unit_id' => $unit->id,
                'paid_at' => now()->toDateString(),
                'total_amount' => 200000, // $2,000 income
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $account->id,
            ]);

            // After payment: 0 + 200000 = 200000.
            $balanceAfterPayment = (int) DB::table('bank_account')->where('id', $account->id)->value('current_balance');
            $this->assertSame(200000, $balanceAfterPayment);

            // 2. Register an expense ($1,200 outgo).
            $category = ExpenseCategory::first();
            $beneficiaryType = BeneficiaryType::findOrFail(BeneficiaryType::OTHER);

            $expenseService = app(ExpenseService::class);
            $expenseService->register([
                'concept' => 'Recolección basura',
                'paid_at' => now()->toDateString(),
                'amount' => 120000,
                'expense_category_id' => $category->id,
                'beneficiary_type_id' => $beneficiaryType->id,
                'year' => now()->year,
                'month' => now()->month,
                'payment_method_id' => $paymentMethod->id,
                'bank_account_id' => $account->id,
            ]);

            // After expense: 200000 - 120000 = 80000.
            $balanceAfterExpense = (int) DB::table('bank_account')->where('id', $account->id)->value('current_balance');
            $this->assertSame(80000, $balanceAfterExpense, 'Balance = payment(200000) - expense(120000) = 80000.');
        });
    }

    // =========================================================================
    // I: Community project ID — FK constraint (added by Projects module)
    // =========================================================================

    public function test_expense_with_community_project_id_stores_uuid_with_fk(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 5000000);

        $tenant->run(function () use ($account): void {
            // Create a real project so the FK constraint is satisfied.
            $status = CommunityProjectStatus::findOrFail(CommunityProjectStatus::DRAFT);
            $category = CommunityProjectCategory::first();
            $participationType = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $amountMode = AmountMode::findOrFail(AmountMode::FREE);

            $project = CommunityProject::create([
                'title' => 'Proyecto de prueba FK',
                'community_project_category_id' => $category->id,
                'participation_type_id' => $participationType->id,
                'amount_mode_id' => $amountMode->id,
                'target_type' => 'all',
                'allow_decline' => true,
                'visible_to_residents' => true,
                'allow_comments' => true,
                'community_project_status_id' => $status->id,
            ]);

            $data = $this->buildExpenseData($account, [
                'community_project_id' => $project->id,
            ]);

            $service = app(ExpenseService::class);
            ['expense' => $expense] = $service->register($data);

            $this->assertSame($project->id, $expense->community_project_id, 'community_project_id should be stored and reference the project.');
        });
    }
}
