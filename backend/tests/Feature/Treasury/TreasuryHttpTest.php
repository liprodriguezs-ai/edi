<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Collections\Models\PaymentMethod;
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
 * HTTP contract tests for the Treasury module (§9.2 matrix + §9.6 invariants).
 *
 * Authorization model: Sanctum token abilities gate every action (tokenCan).
 * A treasurer-shaped token holds create/update/read.expense + read.bank_account
 * but NOT void.expense nor any bank_account write; a president-shaped token adds
 * void.expense + create/update.expense; a resident token holds no treasury
 * abilities. The void.expense ability also discriminates the §9.2 ⚠️ same-day
 * edit window (holders edit anytime; non-holders only same-day, tenant TZ).
 */
final class TreasuryHttpTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private const TREASURER_ABILITIES = [
        'read.expense', 'create.expense', 'update.expense', 'report.expense',
        'read.bank_account',
    ];

    private const PRESIDENT_ABILITIES = [
        'read.expense', 'create.expense', 'update.expense', 'report.expense',
        'void.expense', 'read.bank_account',
    ];

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

    /**
     * @param  array<int, string>  $abilities
     */
    private function makeToken(Tenant $tenant, array $abilities): string
    {
        $user = User::create([
            'name' => 'Treasury '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
        $user->tenants()->attach($tenant->id);

        return $user->createToken('treasury-test', $abilities)->plainTextToken;
    }

    private function makeBankAccount(Tenant $tenant, int $initialBalanceRaw = 5000000): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $initialBalanceRaw): void {
            $account = BankAccount::create([
                'name' => 'Caja '.Str::random(5),
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => Catalog::id(BankAccountPurpose::class, 'Operativa'),
                'currency' => 'MXN',
                'initial_balance' => $initialBalanceRaw,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function expensePayload(Tenant $tenant, BankAccount $account, array $overrides = []): array
    {
        $categoryId = $tenant->run(static fn () => ExpenseCategory::query()->value('id'));

        return array_merge([
            'concept' => 'Egreso de prueba',
            'paid_at' => now()->toDateString(),
            'amount' => 100000,
            'expense_category_id' => $categoryId,
            'beneficiary_type_id' => BeneficiaryType::OTHER,
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'payment_method_id' => PaymentMethod::CASH,
            'bank_account_id' => $account->id,
        ], $overrides);
    }

    private function bankBalanceRaw(Tenant $tenant, string $accountId): int
    {
        return (int) $tenant->run(
            static fn () => DB::table('bank_account')->where('id', $accountId)->value('current_balance')
        );
    }

    private function setExpenseCreatedAt(Tenant $tenant, string $expenseId, \DateTimeInterface $createdAtUtc): void
    {
        $tenant->run(static function () use ($expenseId, $createdAtUtc): void {
            DB::table('expense')->where('id', $expenseId)->update([
                'created_at' => $createdAtUtc->format('Y-m-d H:i:s'),
            ]);
        });
    }

    // =========================================================================
    // §9.2 matrix — registrar / editar / anular egreso
    // =========================================================================

    public function test_president_can_create_edit_and_void_an_expense(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant);
        $president = $this->makeToken($tenant, self::PRESIDENT_ABILITIES);

        $expense = $this->tenantRequest($president, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account))
            ->assertStatus(201)
            ->json('data');

        $this->assertSame(4900000, $this->bankBalanceRaw($tenant, $account->id));

        $this->tenantRequest($president, $tenant)
            ->patchJson('/api/expense/'.$expense['id'], ['concept' => 'Editado por presidencia'])
            ->assertStatus(200);

        $this->tenantRequest($president, $tenant)
            ->postJson('/api/expense/'.$expense['id'].'/void', ['reason' => 'Registrado por error'])
            ->assertStatus(200);

        // Balance restored after void.
        $this->assertSame(5000000, $this->bankBalanceRaw($tenant, $account->id));
    }

    public function test_treasurer_can_create_but_cannot_void_an_expense(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant);
        $treasurer = $this->makeToken($tenant, self::TREASURER_ABILITIES);

        $expense = $this->tenantRequest($treasurer, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($treasurer, $tenant)
            ->postJson('/api/expense/'.$expense['id'].'/void', ['reason' => 'Intento del tesorero'])
            ->assertStatus(403);

        // Balance untouched by the rejected void.
        $this->assertSame(4900000, $this->bankBalanceRaw($tenant, $account->id));
    }

    public function test_resident_can_do_nothing_on_expenses_or_bank_accounts(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant);
        $resident = $this->makeToken($tenant, ['read.unit']);

        $this->tenantRequest($resident, $tenant)->getJson('/api/expense')->assertStatus(403);
        $this->tenantRequest($resident, $tenant)->getJson('/api/bank_account')->assertStatus(403);
        $this->tenantRequest($resident, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account))
            ->assertStatus(403);
    }

    public function test_treasurer_cannot_create_a_bank_account(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $treasurer = $this->makeToken($tenant, self::TREASURER_ABILITIES);

        $this->tenantRequest($treasurer, $tenant)->postJson('/api/bank_account', [
            'name' => 'Cuenta del tesorero',
            'bank_account_type_id' => BankAccountType::CASH,
            'initial_balance' => 0,
        ])->assertStatus(403);
    }

    // =========================================================================
    // Same-day edit window (§9.2 ⚠️) — tenant timezone
    // =========================================================================

    public function test_treasurer_can_edit_a_same_day_expense_but_not_an_older_one(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant);
        $treasurer = $this->makeToken($tenant, self::TREASURER_ABILITIES);

        $expense = $this->tenantRequest($treasurer, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account))
            ->assertStatus(201)
            ->json('data');

        // Same day: cosmetic edit allowed.
        $this->tenantRequest($treasurer, $tenant)
            ->patchJson('/api/expense/'.$expense['id'], ['concept' => 'Corregido mismo día'])
            ->assertStatus(200);

        // Two days old: rejected for the treasurer in any timezone.
        $this->setExpenseCreatedAt($tenant, $expense['id'], now('UTC')->subDays(2));

        $this->tenantRequest($treasurer, $tenant)
            ->patchJson('/api/expense/'.$expense['id'], ['concept' => 'Edición tardía'])
            ->assertStatus(422);

        $tenant->run(function () use ($expense): void {
            $this->assertSame('Corregido mismo día', Expense::findOrFail($expense['id'])->concept);
        });
    }

    public function test_same_day_window_uses_the_tenant_timezone_not_utc(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $this->assertSame('America/Mexico_City', $tenant->refresh()->timezone);

        $account = $this->makeBankAccount($tenant);
        $treasurer = $this->makeToken($tenant, self::TREASURER_ABILITIES);

        $expense = $this->tenantRequest($treasurer, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account))
            ->assertStatus(201)
            ->json('data');

        // 23:30 today in Mexico City = 05:30 TOMORROW in UTC. A naive UTC
        // comparison would reject this edit; the tenant-TZ comparison allows it.
        $mexicoLateToday = now('America/Mexico_City')->setTime(23, 30);
        $this->setExpenseCreatedAt($tenant, $expense['id'], $mexicoLateToday->copy()->setTimezone('UTC'));

        $this->tenantRequest($treasurer, $tenant)
            ->patchJson('/api/expense/'.$expense['id'], ['concept' => 'Mismo día en CDMX'])
            ->assertStatus(200);
    }

    public function test_president_can_edit_an_old_expense_without_window_restriction(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant);
        $president = $this->makeToken($tenant, self::PRESIDENT_ABILITIES);

        $expense = $this->tenantRequest($president, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account))
            ->assertStatus(201)
            ->json('data');

        $this->setExpenseCreatedAt($tenant, $expense['id'], now('UTC')->subDays(30));

        $this->tenantRequest($president, $tenant)
            ->patchJson('/api/expense/'.$expense['id'], ['concept' => 'Corrección histórica'])
            ->assertStatus(200);
    }

    // =========================================================================
    // §9.6 r1 — double void is a clean 422 and never restores twice
    // =========================================================================

    public function test_double_void_returns_422_and_restores_the_balance_only_once(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant);
        $president = $this->makeToken($tenant, self::PRESIDENT_ABILITIES);

        $expense = $this->tenantRequest($president, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account, ['amount' => 150000]))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($president, $tenant)
            ->postJson('/api/expense/'.$expense['id'].'/void', ['reason' => 'Primera anulación'])
            ->assertStatus(200);

        $this->tenantRequest($president, $tenant)
            ->postJson('/api/expense/'.$expense['id'].'/void', ['reason' => 'Segunda anulación'])
            ->assertStatus(422);

        // Restored exactly once: initial 5,000,000.
        $this->assertSame(5000000, $this->bankBalanceRaw($tenant, $account->id));
    }

    // =========================================================================
    // §9.6 r1 — financial fields immutable via PATCH; DELETE disabled
    // =========================================================================

    public function test_expense_amount_account_and_status_cannot_be_changed_via_patch(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant);
        $otherAccount = $this->makeBankAccount($tenant);
        $president = $this->makeToken($tenant, self::PRESIDENT_ABILITIES);

        $expense = $this->tenantRequest($president, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account, ['amount' => 100000]))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($president, $tenant)
            ->patchJson('/api/expense/'.$expense['id'], ['amount' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        $this->tenantRequest($president, $tenant)
            ->patchJson('/api/expense/'.$expense['id'], ['bank_account_id' => $otherAccount->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bank_account_id']);

        // Simulating a void via raw PATCH (status flip without balance restore) is blocked.
        $this->tenantRequest($president, $tenant)
            ->patchJson('/api/expense/'.$expense['id'], [
                'expense_status_id' => ExpenseStatus::VOIDED,
                'void_reason' => 'Anulación pirata',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expense_status_id', 'void_reason']);

        $tenant->run(function () use ($expense, $account): void {
            $fresh = Expense::findOrFail($expense['id']);
            $this->assertSame(100000, (int) $fresh->getRawOriginal('amount'));
            $this->assertSame($account->id, $fresh->bank_account_id);
            $this->assertSame(ExpenseStatus::PAID, $fresh->expense_status_id);
        });

        // Ledger untouched by the rejected edits.
        $this->assertSame(4900000, $this->bankBalanceRaw($tenant, $account->id));
        $this->assertSame(5000000, $this->bankBalanceRaw($tenant, $otherAccount->id));
    }

    public function test_expense_delete_is_disabled_in_favor_of_void(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant);
        $admin = $this->makeToken($tenant, ['*']);

        $expense = $this->tenantRequest($admin, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($admin, $tenant)
            ->deleteJson('/api/expense/'.$expense['id'])
            ->assertStatus(422);

        $tenant->run(function () use ($expense): void {
            $this->assertNotNull(Expense::find($expense['id']), 'Expense must never be (soft-)deleted.');
        });

        // Balance still reflects the registered expense (no phantom restore/leak).
        $this->assertSame(4900000, $this->bankBalanceRaw($tenant, $account->id));
    }

    // =========================================================================
    // §9.6 r3 — current_balance immutable; initial_balance edits recompute it
    // =========================================================================

    public function test_current_balance_cannot_be_set_via_the_api(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 5000000);
        $admin = $this->makeToken($tenant, ['*']);

        $this->tenantRequest($admin, $tenant)
            ->patchJson('/api/bank_account/'.$account->id, ['current_balance' => 99999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_balance']);

        $this->assertSame(5000000, $this->bankBalanceRaw($tenant, $account->id));
    }

    // =========================================================================
    // card_number — optional card tied to the account, digits only
    // =========================================================================

    public function test_card_number_is_persisted_on_create_and_rejected_when_malformed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeToken($tenant, ['*']);

        $purposeId = $tenant->run(static fn () => Catalog::id(BankAccountPurpose::class, 'Operativa'));

        $created = $this->tenantRequest($admin, $tenant)
            ->postJson('/api/bank_account', [
                'name' => 'HSBC Principal',
                'bank_account_type_id' => BankAccountType::BANK,
                'bank_account_purpose_id' => $purposeId,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'card_number' => '4152313312345678',
            ])
            ->assertStatus(201)
            ->json('data');

        $tenant->run(function () use ($created): void {
            $this->assertSame(
                '4152313312345678',
                BankAccount::findOrFail($created['id'])->card_number
            );
        });

        // Separators and short values are rejected: the API stores digits only.
        $this->tenantRequest($admin, $tenant)
            ->patchJson('/api/bank_account/'.$created['id'], ['card_number' => '4152-3133-1234'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['card_number']);

        // Clearing it is allowed — the field is optional.
        $this->tenantRequest($admin, $tenant)
            ->patchJson('/api/bank_account/'.$created['id'], ['card_number' => null])
            ->assertStatus(200);

        $tenant->run(function () use ($created): void {
            $this->assertNull(BankAccount::findOrFail($created['id'])->card_number);
        });
    }

    public function test_editing_initial_balance_recomputes_current_balance_by_delta(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 5000000);
        $admin = $this->makeToken($tenant, ['*']);

        // Register a 1,000,000 expense so current (4,000,000) differs from initial.
        $this->tenantRequest($admin, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account, ['amount' => 1000000]))
            ->assertStatus(201);
        $this->assertSame(4000000, $this->bankBalanceRaw($tenant, $account->id));

        // initial 5,000,000 → 6,000,000 (delta +1,000,000) keeps the movements:
        // current must become 5,000,000, not 6,000,000.
        $this->tenantRequest($admin, $tenant)
            ->patchJson('/api/bank_account/'.$account->id, ['initial_balance' => 6000000])
            ->assertStatus(200);

        $this->assertSame(5000000, $this->bankBalanceRaw($tenant, $account->id));
        $tenant->run(function () use ($account): void {
            $this->assertSame(
                6000000,
                (int) BankAccount::findOrFail($account->id)->getRawOriginal('initial_balance')
            );
        });
    }

    // =========================================================================
    // §9.5.1 / §9.6 r7 — public summary: aggregates only, paid_at semantics
    // =========================================================================

    public function test_public_summary_hides_bank_accounts_from_residents(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $this->makeBankAccount($tenant);

        $resident = $this->makeToken($tenant, ['read.unit']);
        $response = $this->tenantRequest($resident, $tenant)
            ->getJson('/api/treasury/public-summary')
            ->assertStatus(200);

        $this->assertArrayNotHasKey('bank_account_balances', $response->json('data'));
        $this->assertArrayHasKey('total_income', $response->json('data'));
        $this->assertArrayHasKey('total_expenses', $response->json('data'));
        $this->assertArrayHasKey('net_balance', $response->json('data'));
        $this->assertArrayHasKey('expenses_by_category', $response->json('data'));

        // A caller with read.bank_account still gets the per-account breakdown.
        $treasurer = $this->makeToken($tenant, self::TREASURER_ABILITIES);
        $withAccounts = $this->tenantRequest($treasurer, $tenant)
            ->getJson('/api/treasury/public-summary')
            ->assertStatus(200);

        $this->assertArrayHasKey('bank_account_balances', $withAccounts->json('data'));
    }

    public function test_public_summary_buckets_expenses_by_paid_at_not_period_metadata(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $account = $this->makeBankAccount($tenant, 10000000);
        $admin = $this->makeToken($tenant, ['*']);

        // Paid in March but DECLARED as April period metadata: paid_at wins.
        $this->tenantRequest($admin, $tenant)
            ->postJson('/api/expense', $this->expensePayload($tenant, $account, [
                'paid_at' => '2026-03-15',
                'amount' => 250000,
                'year' => 2026,
                'month' => 4,
            ]))
            ->assertStatus(201);

        $march = $this->tenantRequest($admin, $tenant)
            ->getJson('/api/treasury/public-summary?year=2026&month=3')
            ->assertStatus(200)
            ->json('data');
        $this->assertSame('250000', $march['total_expenses']['amount']);
        $this->assertSame('MXN', $march['total_expenses']['currency']);

        $april = $this->tenantRequest($admin, $tenant)
            ->getJson('/api/treasury/public-summary?year=2026&month=4')
            ->assertStatus(200)
            ->json('data');
        $this->assertSame('0', $april['total_expenses']['amount']);
    }
}
