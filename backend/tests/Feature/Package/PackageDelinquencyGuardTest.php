<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Services\PaymentService;
use Modules\Collections\Support\DelinquencySettings;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Package\Models\Package;
use Modules\Package\Models\PackageCarrier;
use Modules\Package\Models\PackageType;
use Modules\Package\Services\PackageService;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountType;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Delinquency hooks 4-5 (design §5): PackageService::register()/batchRegister(),
 * plus the guard-facing PackageController::restrictedUnits() endpoint (design
 * §6.3, R-DELQ-7). Covers R-DELQ-4/5/6/7/9/2 for the package_intake feature.
 */
final class PackageDelinquencyGuardTest extends TestCase
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

    private function makeRoleUser(Tenant $tenant, string $role): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        AppRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string> $abilities */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function linkToUnit(Tenant $tenant, Unit $unit, User $user): void
    {
        $tenant->run(function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
        });
    }

    /** @return array<string, string> */
    private function catalogIds(Tenant $tenant): array
    {
        $ids = [];
        $tenant->run(function () use (&$ids): void {
            $ids['carrier_id'] = PackageCarrier::first()->id;
            $ids['type_id'] = PackageType::first()->id;
        });

        return $ids;
    }

    private function registerPackage(Tenant $tenant, Unit $unit, User $guard): Package
    {
        $pkg = null;
        $tenant->run(function () use ($unit, $guard, &$pkg): void {
            $pkg = app(PackageService::class)->register(['unit_id' => $unit->id], $guard);
        });

        return $pkg;
    }

    /** Makes $unit OVERDUE with a charge owing $balance minor units. Must run inside tenant scope. */
    private function makeUnitOverdue(Unit $unit, int $balance): Charge
    {
        $unit->charge_status_id = ChargeStatus::OVERDUE;
        $unit->save();

        $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));
        $concept = ChargeConcept::create([
            'code' => 'PKDLQ_'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'name' => 'Package delinquency guard test concept',
            'charge_concept_category_id' => $category->id,
            'is_active' => true,
        ]);

        return Charge::create([
            'charge_concept_id' => $concept->id,
            'unit_id' => $unit->id,
            'expected_amount' => $balance,
            'paid_amount' => 0,
            'issued_at' => '2026-01-01',
            'due_date' => '2026-01-10',
            'charge_status_id' => ChargeStatus::OVERDUE,
            'is_cancelled' => false,
        ]);
    }

    private function enablePackageIntakeRestrictions(int $minOverdueAmount = 0): void
    {
        Setting::updateOrCreate(['slug' => DelinquencySettings::RESTRICTIONS_ENABLED], ['name' => DelinquencySettings::RESTRICTIONS_ENABLED, 'slug' => DelinquencySettings::RESTRICTIONS_ENABLED, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT], ['name' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'slug' => DelinquencySettings::MIN_OVERDUE_AMOUNT, 'value' => $minOverdueAmount, 'type' => Setting::TYPE_INTEGER]);
        Setting::updateOrCreate(['slug' => DelinquencySettings::BLOCK_PACKAGE_INTAKE], ['name' => DelinquencySettings::BLOCK_PACKAGE_INTAKE, 'slug' => DelinquencySettings::BLOCK_PACKAGE_INTAKE, 'value' => true, 'type' => Setting::TYPE_BOOLEAN]);
    }

    private function makeBankAccount(Tenant $tenant): BankAccount
    {
        $bank = null;
        $tenant->run(function () use (&$bank): void {
            $bank = BankAccount::create([
                'name' => 'Caja Delq '.uniqid(),
                'bank_account_type_id' => BankAccountType::CASH,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $bank;
    }

    // =========================================================================
    // Hook 4 — register() + privacy (R-DELQ-4, R-DELQ-6)
    // =========================================================================

    public function test_delinquent_unit_is_blocked_from_single_package_intake_with_no_debt_wording(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        $tenant->run(function () use ($unit): void {
            $this->makeUnitOverdue($unit, 750_000);
            $this->enablePackageIntakeRestrictions(500_000);
        });

        $response = $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertStatus(403);

        $payload = $response->json();
        $raw = json_encode($payload);

        $this->assertSame('DELINQUENT_RESTRICTED', $payload['code']);
        $this->assertSame('Esta unidad no puede recibir paquetes.', $payload['message']);
        $this->assertArrayNotHasKey('overdue_balance', $payload);
        $this->assertArrayNotHasKey('notice_text', $payload);
        $this->assertStringNotContainsString('adeudo', $raw);
        $this->assertStringNotContainsString('moroso', $raw);
        $this->assertStringNotContainsString('deuda', $raw);

        $tenant->run(function (): void {
            $this->assertSame(0, Package::count(), 'No package may be persisted for a restricted unit');
        });
    }

    // =========================================================================
    // Hook 5 — batchRegister()
    // =========================================================================

    public function test_delinquent_unit_in_batch_blocks_the_whole_batch_before_any_persistence(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeRoleUser($tenant, 'security');
        $goodUnit = $this->makeUnit($tenant);
        $restrictedUnit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        $tenant->run(function () use ($restrictedUnit): void {
            $this->makeUnitOverdue($restrictedUnit, 750_000);
            $this->enablePackageIntakeRestrictions(500_000);
        });

        $response = $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package/intake-batch', [
                'packages' => [
                    ['unit_id' => $goodUnit->id, 'package_carrier_id' => $catalog['carrier_id'], 'package_type_id' => $catalog['type_id']],
                    ['unit_id' => $restrictedUnit->id, 'package_carrier_id' => $catalog['carrier_id'], 'package_type_id' => $catalog['type_id']],
                ],
            ])
            ->assertStatus(403);

        $response->assertJsonPath('code', 'DELINQUENT_RESTRICTED');

        $tenant->run(function (): void {
            $this->assertSame(0, Package::count(), 'The whole batch rolls back, including the good unit row');
        });
    }

    /**
     * infra-cost fix: batchRegister() computes restrictedUnitIds() ONCE and now
     * passes it into register() as a pre-checked set, so the per-row delinquency
     * guard becomes an in-memory in_array instead of re-querying
     * unit.charge_status_id (and, when min_overdue_amount > 0, the balance) for
     * every row. Proves BOTH the allowed OVERDUE-but-below-threshold case still
     * works AND that the fix actually removed the per-row query.
     */
    public function test_batch_register_allows_overdue_below_threshold_unit_without_a_per_row_charge_status_query(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        // OVERDUE but BELOW the 500_000 threshold — allowed, yet still exercises
        // the per-row delinquency check path (min > 0, so evaluate() would
        // normally also query the balance).
        $tenant->run(function () use ($unit): void {
            $this->makeUnitOverdue($unit, 100_000);
            $this->enablePackageIntakeRestrictions(500_000);
        });

        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ];
        }

        $perRowChargeStatusLookups = 0;
        DB::listen(function ($query) use (&$perRowChargeStatusLookups): void {
            // The exact shape of DelinquencyRestrictionService::evaluate()'s
            // per-unit lookup (Unit::query()->where('id',$unitId)->value('charge_status_id')).
            // restrictedUnitIds()'s own OVERDUE pluck has a different WHERE clause
            // (charge_status_id = ?, not id = ?), so this pattern is unambiguous.
            if (str_contains($query->sql, 'select "charge_status_id" from "unit" where "id" = ?')) {
                $perRowChargeStatusLookups++;
            }
        });

        $response = $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package/intake-batch', ['packages' => $rows])
            ->assertStatus(201);

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(
            0,
            $perRowChargeStatusLookups,
            'batchRegister() must reuse the precomputed restrictedUnitIds() set, not re-query charge_status_id per row',
        );
    }

    // =========================================================================
    // R-DELQ-5 — never blocked
    // =========================================================================

    public function test_handover_and_verify_code_are_unaffected_for_an_already_registered_package(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);

        // Registered BEFORE the unit goes delinquent.
        $pkg = $this->registerPackage($tenant, $unit, $security);

        $code = null;
        $tenant->run(function () use ($pkg, $unit, &$code): void {
            $code = Package::findOrFail($pkg->id)->pickup_code;
            $this->makeUnitOverdue($unit, 750_000);
            $this->enablePackageIntakeRestrictions(500_000);
        });

        $this->tenantRequest($this->token($security, ['update.package']), $tenant)
            ->postJson('/api/package/verify-code', ['pickup_code' => $code])
            ->assertOk()
            ->assertJsonPath('valid', true);

        // require_pickup_code defaults to true (TenantCatalogSeeder) — supply the
        // code resolved above rather than disabling the setting, so this test also
        // proves the code-gated path itself is unaffected by delinquency.
        $this->tenantRequest($this->token($security, ['update.package']), $tenant)
            ->postJson('/api/package/handover', [
                'package_ids' => [$pkg->id],
                'pickup_code' => $code,
                'pickup_proxy_name' => 'María López',
                'pickup_proxy_relationship' => 'Esposa',
            ])
            ->assertOk();
    }

    // =========================================================================
    // R-DELQ-7 — restricted-units endpoint
    // =========================================================================

    public function test_restricted_units_endpoint_returns_only_unit_ids(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeRoleUser($tenant, 'security');
        $restrictedUnit = $this->makeUnit($tenant);
        $goodUnit = $this->makeUnit($tenant);

        $tenant->run(function () use ($restrictedUnit): void {
            $this->makeUnitOverdue($restrictedUnit, 750_000);
            $this->enablePackageIntakeRestrictions(500_000);
        });

        $response = $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->getJson('/api/package/restricted-units')
            ->assertOk();

        $response->assertExactJson(['unit_ids' => [$restrictedUnit->id]]);
        $this->assertNotContains($goodUnit->id, $response->json('unit_ids'));
    }

    public function test_resident_cannot_call_restricted_units_endpoint(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeRoleUser($tenant, 'resident');

        $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->getJson('/api/package/restricted-units')
            ->assertStatus(403);
    }

    // =========================================================================
    // R-DELQ-9 — immediate unblock, same-transaction recompute
    // =========================================================================

    public function test_a_payment_that_clears_the_balance_immediately_unblocks_package_intake(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeRoleUser($tenant, 'security');
        $unit = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);
        $bank = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit): void {
            $this->makeUnitOverdue($unit, 500_000);
            $this->enablePackageIntakeRestrictions(500_000);
        });

        // Blocked while the debt stands.
        $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertStatus(403);

        // Payment settles the OVERDUE charge in full — ChargeStatusService recomputes
        // unit.charge_status_id in the same transaction as the payment application.
        $tenant->run(function () use ($unit, $bank): void {
            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-02-01',
                'total_amount' => 500_000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $bank->id,
            ], true);
        });

        // Immediately unblocked — no separate recompute step.
        $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unit->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // R-DELQ-2 — money rounding cross-check with the config PUT (3.3)
    // =========================================================================

    public function test_threshold_boundary_blocks_at_but_not_one_cent_below(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeRoleUser($tenant, 'security');
        $unitAtThreshold = $this->makeUnit($tenant);
        $unitBelowThreshold = $this->makeUnit($tenant);
        $catalog = $this->catalogIds($tenant);

        $tenant->run(function () use ($unitAtThreshold, $unitBelowThreshold): void {
            $this->makeUnitOverdue($unitAtThreshold, 500_000);
            $this->makeUnitOverdue($unitBelowThreshold, 499_999);
            $this->enablePackageIntakeRestrictions(500_000);
        });

        $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unitAtThreshold->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertStatus(403);

        $this->tenantRequest($this->token($security, ['create.package']), $tenant)
            ->postJson('/api/package', [
                'unit_id' => $unitBelowThreshold->id,
                'package_carrier_id' => $catalog['carrier_id'],
                'package_type_id' => $catalog['type_id'],
            ])
            ->assertStatus(201);
    }
}
