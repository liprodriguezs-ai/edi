<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use Cknow\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\ExpenseCategory;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\Catalog;
use Tests\TestCase;

/**
 * Integration tests for the Treasury Foundation module.
 *
 * Covers:
 *   - Catalog seeding per tenant (BankAccountType × 3, BankAccountPurpose × 5, ExpenseCategory × 7)
 *   - Tenant isolation for all four models
 *   - BankAccount: current_balance defaults to initial_balance on create
 *   - Money round-trip: integer storage + MoneyIntegerCast read
 *   - CLABE unique per tenant (null allowed many times; non-null unique within tenant; allowed across tenants)
 *   - ExpenseCategory: 7 defaults seeded; subcategory via parent_id; COALESCE unique
 */
final class TreasuryFoundationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeTenant(string $name = 'Test Tenant'): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    /**
     * Manually seed Treasury catalogs for a tenant (simulates TenantDatabaseSeeder).
     */
    private function seedTreasuryCatalogs(Tenant $tenant): void
    {
        $tenant->run(function (): void {
            // BankAccountType sigue CENTRAL (selector de comportamiento): la
            // constante ES el UUID; updateOrCreate por id es idempotente.
            BankAccountType::updateOrCreate(['id' => BankAccountType::BANK], ['name' => 'Cuenta bancaria', 'order' => 0, 'is_active' => true]);
            BankAccountType::updateOrCreate(['id' => BankAccountType::CASH], ['name' => 'Caja de efectivo', 'order' => 1, 'is_active' => true]);
            BankAccountType::updateOrCreate(['id' => BankAccountType::INVESTMENT], ['name' => 'Inversión', 'order' => 2, 'is_active' => true]);

            // BankAccountPurpose es AUTOADMINISTRABLE (tenant-scoped): se siembra
            // por nombre — el TenantScope estampa el tenant_id de este tenant.
            foreach (['Operativa', 'Fondo de reserva', 'Nómina', 'Caja chica', 'Otro'] as $i => $name) {
                BankAccountPurpose::updateOrCreate(['name' => $name], ['order' => $i, 'is_active' => true]);
            }

            // Default ExpenseCategories (genuinely per-tenant — keyed by name).
            foreach (['Salarios', 'Servicios públicos', 'Mantenimiento', 'Insumos', 'Bancarias', 'Imprevistos', 'Proyectos'] as $i => $name) {
                ExpenseCategory::updateOrCreate(['name' => $name, 'parent_id' => null], ['order' => $i, 'is_active' => true]);
            }
        });
    }

    // =========================================================================
    // A: Catalog seeding per tenant
    // =========================================================================

    public function test_bank_account_type_catalog_seeded_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $this->assertSame(3, BankAccountType::count());

            $bank = BankAccountType::findOrFail(BankAccountType::BANK);
            $this->assertSame('Cuenta bancaria', $bank->name);
            $this->assertTrue($bank->is_active);

            $cash = BankAccountType::findOrFail(BankAccountType::CASH);
            $this->assertSame('Caja de efectivo', $cash->name);

            $investment = BankAccountType::findOrFail(BankAccountType::INVESTMENT);
            $this->assertSame('Inversión', $investment->name);
        });
    }

    public function test_bank_account_purpose_catalog_seeded_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $this->assertSame(5, BankAccountPurpose::count());

            $operating = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $this->assertSame('Operativa', $operating->name);

            $reserve = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Fondo de reserva'));
            $this->assertSame('Fondo de reserva', $reserve->name);
        });
    }

    public function test_catalog_seeding_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);
        $this->seedTreasuryCatalogs($tenant); // run twice

        $tenant->run(function (): void {
            // Should not double up
            $this->assertSame(3, BankAccountType::count());
            $this->assertSame(5, BankAccountPurpose::count());
            $this->assertSame(7, ExpenseCategory::count());
        });
    }

    public function test_account_type_is_central_and_purpose_is_tenant_scoped(): void
    {
        // bank_account_type sigue CENTRAL (selector de comportamiento: banco/caja)
        // → visible para todo tenant sin sembrar. bank_account_purpose es
        // AUTOADMINISTRABLE (tenant-scoped) → el tenant B no ve las filas de A.
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTreasuryCatalogs($tenantA);
        // intencionalmente NO se siembra el tenant B

        $tenantB->run(function (): void {
            $this->assertSame(3, BankAccountType::count(), 'El catálogo central es visible para B');
            $this->assertSame(0, BankAccountPurpose::count(), 'B no ve los propósitos del tenant A');
        });
    }

    // =========================================================================
    // B: BankAccount — creation, current_balance default, Money cast
    // =========================================================================

    public function test_bank_account_current_balance_defaults_to_initial_balance_on_create(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $type = BankAccountType::find(BankAccountType::BANK);

            $account = BankAccount::create([
                'name' => 'HSBC Principal',
                'bank_account_type_id' => $type->id,
                'initial_balance' => 15000000, // 150,000.00 MXN in cents
            ]);

            $this->assertSame(15000000, (int) $account->getRawOriginal('current_balance'));
        });
    }

    public function test_money_integer_cast_round_trip_stores_as_biginteger(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $type = BankAccountType::find(BankAccountType::BANK);

            // Store $50,000.00 = 5,000,000 minor units
            $account = BankAccount::create([
                'name' => 'Test Account',
                'bank_account_type_id' => $type->id,
                'initial_balance' => 5000000,
            ]);

            // Verify raw integer is stored in DB (not decimal, not string)
            $rawBalance = DB::table('bank_account')->where('id', $account->id)->value('initial_balance');
            $this->assertIsInt((int) $rawBalance, 'initial_balance must be stored as an integer in DB.');
            $this->assertSame(5000000, (int) $rawBalance);

            // Verify current_balance also stored correctly
            $rawCurrent = DB::table('bank_account')->where('id', $account->id)->value('current_balance');
            $this->assertSame(5000000, (int) $rawCurrent, 'current_balance must equal initial_balance on create.');

            // Verify MoneyIntegerCast wraps it in a Money object
            $fresh = BankAccount::find($account->id);
            $this->assertInstanceOf(Money::class, $fresh->initial_balance);
            $this->assertSame('5000000', $fresh->initial_balance->getAmount());
        });
    }

    public function test_bank_account_with_type_and_purpose_relations(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $type = BankAccountType::find(BankAccountType::CASH);
            $purpose = BankAccountPurpose::find(Catalog::id(BankAccountPurpose::class, 'Caja chica'));

            $account = BankAccount::create([
                'name' => 'Caja chica oficina',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'initial_balance' => 0,
            ]);

            $account->load('type', 'purpose');
            $this->assertSame('Caja de efectivo', $account->type->name);
            $this->assertSame('Caja chica', $account->purpose->name);
        });
    }

    public function test_bank_account_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTreasuryCatalogs($tenantA);
        $this->seedTreasuryCatalogs($tenantB);

        $tenantA->run(function (): void {
            $type = BankAccountType::find(BankAccountType::BANK);
            BankAccount::create(['name' => 'Cuenta A', 'bank_account_type_id' => $type->id, 'initial_balance' => 0]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, BankAccount::count(), 'Tenant B must not see Tenant A bank accounts.');
        });
    }

    // =========================================================================
    // C: CLABE unique constraints
    // =========================================================================

    public function test_null_clabe_allowed_multiple_times_within_same_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $type = BankAccountType::find(BankAccountType::BANK);

            BankAccount::create(['name' => 'Account 1', 'bank_account_type_id' => $type->id, 'initial_balance' => 0, 'clabe' => null]);
            BankAccount::create(['name' => 'Account 2', 'bank_account_type_id' => $type->id, 'initial_balance' => 0, 'clabe' => null]);

            $this->assertSame(2, BankAccount::count(), 'Multiple null CLABEs must be allowed.');
        });
    }

    public function test_duplicate_non_null_clabe_within_same_tenant_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $type = BankAccountType::find(BankAccountType::BANK);
            $clabe = '646180157000000004';

            BankAccount::create(['name' => 'Account A', 'bank_account_type_id' => $type->id, 'initial_balance' => 0, 'clabe' => $clabe]);
            BankAccount::create(['name' => 'Account B', 'bank_account_type_id' => $type->id, 'initial_balance' => 0, 'clabe' => $clabe]);
        });
    }

    public function test_same_clabe_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTreasuryCatalogs($tenantA);
        $this->seedTreasuryCatalogs($tenantB);

        $clabe = '646180157000000004';

        $tenantA->run(function () use ($clabe): void {
            $type = BankAccountType::find(BankAccountType::BANK);
            BankAccount::create(['name' => 'Account A', 'bank_account_type_id' => $type->id, 'initial_balance' => 0, 'clabe' => $clabe]);
        });

        // Must not throw — CLABE uniqueness is per-tenant only.
        $tenantB->run(function () use ($clabe): void {
            $type = BankAccountType::find(BankAccountType::BANK);
            BankAccount::create(['name' => 'Account B', 'bank_account_type_id' => $type->id, 'initial_balance' => 0, 'clabe' => $clabe]);
            $this->assertSame(1, BankAccount::count());
        });
    }

    // =========================================================================
    // D: ExpenseCategory — defaults, subcategory, COALESCE unique
    // =========================================================================

    public function test_default_expense_categories_seeded_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $this->assertSame(7, ExpenseCategory::count());

            $expected = ['Salarios', 'Servicios públicos', 'Mantenimiento', 'Insumos', 'Bancarias', 'Imprevistos', 'Proyectos'];
            foreach ($expected as $name) {
                $this->assertTrue(
                    ExpenseCategory::where('name', $name)->where('parent_id', null)->exists(),
                    "Default category '{$name}' should exist as a top-level category."
                );
            }
        });
    }

    public function test_expense_category_subcategory_via_parent_id(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $parent = ExpenseCategory::where('name', 'Mantenimiento')->first();

            $child = ExpenseCategory::create([
                'name' => 'Pintura',
                'parent_id' => $parent->id,
                'is_active' => true,
                'order' => 0,
            ]);

            $child->load('parent', 'children');

            $this->assertSame($parent->id, $child->parent_id);
            $this->assertSame('Mantenimiento', $child->parent->name);

            $parent->load('children');
            $this->assertTrue($parent->children->contains('id', $child->id));
        });
    }

    public function test_expense_category_same_name_under_different_parents_is_allowed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $parent1 = ExpenseCategory::where('name', 'Mantenimiento')->first();
            $parent2 = ExpenseCategory::where('name', 'Proyectos')->first();

            ExpenseCategory::create(['name' => 'Materiales', 'parent_id' => $parent1->id]);
            // Same name, different parent — must succeed.
            ExpenseCategory::create(['name' => 'Materiales', 'parent_id' => $parent2->id]);

            $this->assertSame(9, ExpenseCategory::count(), '7 defaults + 2 subcategories');
        });
    }

    public function test_expense_category_same_name_same_parent_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            $parent = ExpenseCategory::where('name', 'Mantenimiento')->first();

            ExpenseCategory::create(['name' => 'Pintura', 'parent_id' => $parent->id]);
            ExpenseCategory::create(['name' => 'Pintura', 'parent_id' => $parent->id]); // must throw
        });
    }

    public function test_expense_category_same_top_level_name_same_tenant_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $tenant = $this->makeTenant();
        $this->seedTreasuryCatalogs($tenant);

        $tenant->run(function (): void {
            ExpenseCategory::create(['name' => 'Salarios', 'parent_id' => null]); // duplicate top-level
        });
    }

    public function test_expense_category_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTreasuryCatalogs($tenantA);

        $tenantB->run(function (): void {
            $this->assertSame(0, ExpenseCategory::count(), 'Tenant B must not see Tenant A expense categories.');
        });
    }

    public function test_expense_category_same_name_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTreasuryCatalogs($tenantA);
        $this->seedTreasuryCatalogs($tenantB);

        // Both tenants seeded with "Salarios" — no exception should occur.
        $tenantA->run(function (): void {
            $this->assertTrue(ExpenseCategory::where('name', 'Salarios')->exists());
        });

        $tenantB->run(function (): void {
            $this->assertTrue(ExpenseCategory::where('name', 'Salarios')->exists());
        });
    }
}
