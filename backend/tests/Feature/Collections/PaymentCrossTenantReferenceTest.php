<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use Database\Seeders\CatalogSeeder;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Collections\Models\PaymentMethod;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Database\Seeders\TenantDatabaseSeeder;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * POST /payment/register must reject a `unit_id` or `bank_account_id` that
 * belongs to ANOTHER tenant. Both are tenant-scoped (unit + bank_account carry
 * BelongsToTenant), so a payment in tenant A that references tenant B's UUID is
 * a cross-tenant FK reference that corrupts ledger integrity (plan 036). The
 * tenant-scoped Rule::exists at the request boundary must turn those into 422.
 *
 * A same-tenant unit + bank still registers (201), proving the rule does not
 * over-reject legitimate references.
 *
 * NOTE: the central CatalogSeeder is run exactly ONCE (it is non-idempotent for
 * chat_channel_type within a single process — a pre-existing seeder defect at
 * CatalogSeeder.php:402), so this test does NOT use the trait's twoTenants()
 * helper, which seeds the central catalogs once per tenant. Only the per-tenant
 * TenantDatabaseSeeder is run for each tenant.
 */
final class PaymentCrossTenantReferenceTest extends TestCase
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

    public function test_register_rejects_unit_id_from_another_tenant(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->bootstrapTwoTenants();

        // Foreign unit lives in tenant B; bank + method are valid in tenant A.
        $foreignUnit = $this->makeUnit($tenantB);
        $bankA = $this->makeBankAccount($tenantA);
        $cashA = $this->inTenant($tenantA, fn () => PaymentMethod::CASH);

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/payment/register', [
                'unit_id' => $foreignUnit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $cashA,
                'bank_account_id' => $bankA->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['unit_id']);
    }

    public function test_register_rejects_bank_account_id_from_another_tenant(): void
    {
        [$tenantA, $tokenA, $tenantB] = $this->bootstrapTwoTenants();

        // Foreign bank account lives in tenant B; unit + method are valid in tenant A.
        $unitA = $this->makeUnit($tenantA);
        $foreignBank = $this->makeBankAccount($tenantB);
        $cashA = $this->inTenant($tenantA, fn () => PaymentMethod::CASH);

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/payment/register', [
                'unit_id' => $unitA->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $cashA,
                'bank_account_id' => $foreignBank->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bank_account_id']);
    }

    public function test_register_accepts_same_tenant_unit_and_bank(): void
    {
        [$tenantA, $tokenA] = $this->bootstrapTwoTenants();

        // Own-tenant references must NOT be rejected by the tenant-scoped rule.
        $unitA = $this->makeUnit($tenantA);
        $bankA = $this->makeBankAccount($tenantA);
        $cashA = $this->inTenant($tenantA, fn () => PaymentMethod::CASH);

        $this->tenantRequest($tokenA, $tenantA)
            ->postJson('/api/payment/register', [
                'unit_id' => $unitA->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $cashA,
                'bank_account_id' => $bankA->id,
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build two seeded tenants and a wildcard admin token for tenant A, running
     * the non-idempotent central CatalogSeeder exactly once (see class docblock).
     *
     * @return array{0: Tenant, 1: string, 2: Tenant}
     */
    private function bootstrapTwoTenants(): array
    {
        $this->seedRolePermissions();

        // Central catalogs (FSM/behavior, no tenant_id) — run ONCE for both tenants.
        (new CatalogSeeder)->run();

        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenantData($tenantA);
        $this->seedTenantData($tenantB);

        // Wildcard token isolates the test to TenantScope, not permission checks.
        $admin = $this->makeUser($tenantA);
        $tokenA = $admin->createToken('admin', ['*'])->plainTextToken;

        return [$tenantA, $tokenA, $tenantB];
    }

    /**
     * Seed only the per-tenant data (tenant-scoped catalogs, chat channels,
     * notification templates) for the given tenant — never the central catalogs.
     */
    private function seedTenantData(Tenant $tenant): void
    {
        $tenant->run(function (): void {
            Artisan::call('db:seed', [
                '--class' => TenantDatabaseSeeder::class,
                '--force' => true,
            ]);
        });
    }

    private function seedRolePermissions(): void
    {
        Artisan::call('db:seed', [
            '--class' => ResetPermissionTableSeeder::class,
            '--force' => true,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

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

    private function makeBankAccount(Tenant $tenant): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }
}
