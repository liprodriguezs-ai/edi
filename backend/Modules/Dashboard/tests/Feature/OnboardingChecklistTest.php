<?php

declare(strict_types=1);

namespace Modules\Dashboard\Tests\Feature;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\Periodicity;
use Modules\Company\Models\Company;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountType;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for GET /api/dashboard/onboarding-checklist.
 *
 * Covers:
 *  - 401 without authentication
 *  - Brand-new tenant → all required items pending, is_complete=false
 *  - Each predicate individually flips to completed
 *  - All 8 required items done → is_complete=true
 *  - The optional "Tipos de unidad" (catalogs) item is no longer listed
 *  - charge_concepts ignores the seeded technical OTHER concept
 *  - Non-admin short-circuit → empty items, is_complete=true
 *  - Tenant isolation → data from another tenant does not count
 */
final class OnboardingChecklistTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private Tenant $tenant;

    private User $adminUser;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure Spatie roles exist before any assignRole() calls.
        $this->ensureRoles();

        $this->tenant = $this->makeTenant('Onboarding Test Cluster');
        $this->seedTenant($this->tenant);

        $this->adminUser = $this->makeAdminUser($this->tenant);
        $this->adminToken = $this->adminUser->createToken('admin', ['*'])->plainTextToken;
    }

    /**
     * Ensure all Spatie roles used in this test class exist (guard api).
     * Other test modules create them inline; we follow the same pattern.
     */
    private function ensureRoles(): void
    {
        foreach (['administrator', 'account.owner', 'resident'] as $slug) {
            AppRole::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

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

    private function makeAdminUser(Tenant $tenant, string $role = 'administrator'): User
    {
        $user = User::create([
            'name' => 'Admin '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);
        $user->assignRole($role);

        return $user;
    }

    private function makeResidentUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Resident '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);
        $user->assignRole('resident');

        return $user;
    }

    private function getChecklist(): TestResponse
    {
        return $this->tenantRequest($this->adminToken, $this->tenant)
            ->getJson('/api/dashboard/onboarding-checklist');
    }

    private function assertItemCompleted(string $id, bool $completed, array $items): void
    {
        $item = collect($items)->firstWhere('id', $id);
        $this->assertNotNull($item, "Item '{$id}' not found in checklist");
        $this->assertSame(
            $completed,
            $item['completed'],
            "Expected item '{$id}' to be ".($completed ? 'completed' : 'pending')
        );
    }

    // =========================================================================
    // Auth guard
    // =========================================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/dashboard/onboarding-checklist');

        $response->assertUnauthorized();
    }

    // =========================================================================
    // Brand-new tenant — all pending
    // =========================================================================

    public function test_brand_new_tenant_has_all_required_items_pending(): void
    {
        $response = $this->getChecklist();

        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame(8, $data['required_total']);
        // collection_params is satisfied out of the box by CollectionsDatabaseSeeder
        // (defaults for charge_generation_day + due_soon_days); the other 7 are pending.
        $this->assertSame(1, $data['required_completed']);
        $this->assertSame(13, $data['progress_percentage']);
        $this->assertFalse($data['is_complete']);

        $requiredItems = collect($data['items'])->filter(fn ($i) => $i['required']);
        $this->assertCount(8, $requiredItems);

        // collection_params is the only completed required item on a fresh tenant.
        $this->assertItemCompleted('collection_params', true, $data['items']);
        foreach ($requiredItems as $item) {
            if ($item['id'] === 'collection_params') {
                continue;
            }
            $this->assertFalse($item['completed'], "Item '{$item['id']}' should be pending");
        }
    }

    // =========================================================================
    // Individual predicates
    // =========================================================================

    public function test_legal_branding_completed_when_company_has_legal_name_and_rfc(): void
    {
        $this->tenant->run(function (): void {
            Company::create([
                'name' => 'Test Cluster',
                'slug' => 'test-cluster-'.Str::random(6),
                'main' => true,
                'tenant_id' => tenant()->getTenantKey(),
                'legal_name' => 'Condominio Las Palmas S.A. de C.V.',
                'rfc' => 'CLP990101ABC',
            ]);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('legal_branding', true, $items);
    }

    public function test_legal_branding_pending_when_rfc_is_missing(): void
    {
        $this->tenant->run(function (): void {
            Company::create([
                'name' => 'Test Cluster',
                'slug' => 'test-cluster-'.Str::random(6),
                'main' => true,
                'tenant_id' => tenant()->getTenantKey(),
                'legal_name' => 'Condominio Las Palmas S.A. de C.V.',
                'rfc' => null,
            ]);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('legal_branding', false, $items);
    }

    public function test_collection_params_completed_when_settings_exist(): void
    {
        // CollectionsDatabaseSeeder (run by seedTenant) seeds charge_generation_day
        // and due_soon_days with defaults, so collection_params starts completed.
        $this->tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'collections.charge_generation_day'],
                ['name' => 'Día de generación', 'value' => 5, 'type' => Setting::TYPE_INTEGER],
            );
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días por vencer', 'value' => 7, 'type' => Setting::TYPE_INTEGER],
            );
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('collection_params', true, $items);
    }

    public function test_collection_params_completed_by_default_seeding(): void
    {
        // No manual settings: the tenant seeder already seeded the two slugs with
        // defaults, so the collection_params item is satisfied out of the box.
        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('collection_params', true, $items);
    }

    public function test_towers_sections_completed_when_tower_exists(): void
    {
        $this->tenant->run(function (): void {
            Tower::factory()->create(['name' => 'Torre A']);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('towers_sections', true, $items);
    }

    public function test_units_completed_when_unit_exists(): void
    {
        $this->tenant->run(function (): void {
            Unit::factory()->create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('units', true, $items);
    }

    public function test_residents_completed_when_unit_user_exists(): void
    {
        $this->tenant->run(function (): void {
            $unit = Unit::factory()->create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
            UnitUser::factory()->create([
                'unit_id' => $unit->id,
                'user_id' => $this->adminUser->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('residents', true, $items);
    }

    public function test_board_treasurer_completed_when_current_period_has_treasurer(): void
    {
        $member = $this->makeAdminUser($this->tenant);

        $this->tenant->run(function () use ($member): void {
            $period = BoardPeriod::create([
                'name' => 'Mesa 2024',
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            BoardMember::create([
                'board_period_id' => $period->id,
                'user_id' => $member->id,
                'board_role_id' => BoardRole::TREASURER,
            ]);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('board_treasurer', true, $items);
    }

    public function test_board_treasurer_pending_when_period_has_no_treasurer(): void
    {
        $member = $this->makeAdminUser($this->tenant);

        $this->tenant->run(function () use ($member): void {
            $period = BoardPeriod::create([
                'name' => 'Mesa 2024',
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            // Only president assigned, no treasurer
            BoardMember::create([
                'board_period_id' => $period->id,
                'user_id' => $member->id,
                'board_role_id' => BoardRole::PRESIDENT,
            ]);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('board_treasurer', false, $items);
    }

    public function test_bank_account_completed_when_bank_account_exists(): void
    {
        $this->tenant->run(function (): void {
            // BankAccountType is a central catalog seeded by CatalogSeeder — use constant.
            // BankAccount::factory() is not available (no newFactory() on model); create directly.
            BankAccount::create([
                'name' => 'Cuenta Principal',
                'bank_account_type_id' => BankAccountType::BANK,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('bank_account', true, $items);
    }

    public function test_charge_concepts_completed_when_charge_concept_exists(): void
    {
        $this->tenant->run(function (): void {
            ChargeConcept::create([
                'name' => 'Mantenimiento mensual',
                'code' => 'MANT-001',
                'charge_concept_category_id' => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'),
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'periodicity_id' => Periodicity::MONTHLY,
                'fixed_amount' => 15000,
                'grace_days' => 5,
                'valid_from' => now()->startOfMonth()->toDateString(),
                'is_active' => true,
            ]);
        });

        $response = $this->getChecklist();
        $items = $response->json('data.items');

        $this->assertItemCompleted('charge_concepts', true, $items);
    }

    /**
     * The optional "Tipos de unidad" (catalogs) item was REMOVED from the checklist:
     * TenantCatalogSeeder already seeds the unit types, so the step was born complete
     * and only added noise. Every remaining item is required.
     */
    public function test_optional_catalogs_item_is_no_longer_part_of_the_checklist(): void
    {
        $this->completeAllRequiredItems();

        $response = $this->getChecklist();
        $data = $response->json('data');

        $this->assertNull(
            collect($data['items'])->firstWhere('id', 'catalogs'),
            'The optional catalogs item must no longer be listed.',
        );

        foreach ($data['items'] as $item) {
            $this->assertTrue($item['required'], "Item {$item['id']} should be required.");
        }
    }

    /**
     * A freshly-seeded tenant always carries the technical `OTHER` charge concept
     * (the free-text cobro-directo fallback resolved by DirectChargeService). Counting
     * it marked this step complete before the admin had created a single real concept.
     */
    public function test_charge_concepts_item_ignores_the_seeded_technical_other_concept(): void
    {
        $seededOther = $this->inTenant(
            $this->tenant,
            fn (): bool => ChargeConcept::where('code', ChargeConcept::CODE_OTHER)->exists(),
        );
        $this->assertTrue($seededOther, 'TenantCatalogSeeder should seed the technical OTHER concept.');

        $items = $this->getChecklist()->json('data.items');

        $this->assertItemCompleted('charge_concepts', false, $items);
    }

    // =========================================================================
    // All required completed
    // =========================================================================

    public function test_is_complete_true_when_all_required_items_are_done(): void
    {
        $this->completeAllRequiredItems();

        $response = $this->getChecklist();
        $data = $response->json('data');

        $this->assertTrue($data['is_complete']);
        $this->assertSame(8, $data['required_completed']);
        $this->assertSame(100, $data['progress_percentage']);
    }

    // =========================================================================
    // Non-admin short-circuit
    // =========================================================================

    public function test_non_admin_user_receives_empty_complete_response(): void
    {
        $resident = $this->makeResidentUser($this->tenant);
        $residentToken = $resident->createToken('resident', ['*'])->plainTextToken;

        $response = $this->tenantRequest($residentToken, $this->tenant)
            ->getJson('/api/dashboard/onboarding-checklist');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame([], $data['items']);
        $this->assertSame(0, $data['required_total']);
        $this->assertTrue($data['is_complete']);
    }

    public function test_account_owner_role_receives_full_checklist(): void
    {
        $owner = $this->makeAdminUser($this->tenant, 'account.owner');
        $ownerToken = $owner->createToken('owner', ['*'])->plainTextToken;

        $response = $this->tenantRequest($ownerToken, $this->tenant)
            ->getJson('/api/dashboard/onboarding-checklist');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(8, $data['items']); // 8 required, no optional items
        $this->assertSame(8, $data['required_total']);
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_data_from_another_tenant_does_not_count(): void
    {
        // Create data in tenant B
        $tenantB = $this->makeTenant('Other Cluster');
        $this->seedTenant($tenantB);

        $tenantB->run(function (): void {
            Tower::factory()->create(['name' => 'Torre B']);
            Unit::factory()->create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        // Tenant A should still have pending items
        $response = $this->getChecklist();
        $data = $response->json('data');

        $this->assertItemCompleted('towers_sections', false, $data['items']);
        $this->assertItemCompleted('units', false, $data['items']);
        $this->assertFalse($data['is_complete']);
    }

    // =========================================================================
    // Response structure
    // =========================================================================

    public function test_response_has_expected_structure(): void
    {
        $response = $this->getChecklist();

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => [
                            'id',
                            'title',
                            'description',
                            'details',
                            'route',
                            'required',
                            'completed',
                            'count',
                        ],
                    ],
                    'required_total',
                    'required_completed',
                    'progress_percentage',
                    'is_complete',
                ],
            ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function completeAllRequiredItems(): void
    {
        $treasurer = $this->makeAdminUser($this->tenant);

        $this->tenant->run(function () use ($treasurer): void {
            // 1. legal_branding
            Company::create([
                'name' => 'Test Cluster',
                'slug' => 'test-cluster-'.Str::random(6),
                'main' => true,
                'tenant_id' => tenant()->getTenantKey(),
                'legal_name' => 'Condominio Las Palmas S.A. de C.V.',
                'rfc' => 'CLP990101ABC',
            ]);

            // 2. collection_params (already seeded with defaults by
            // CollectionsDatabaseSeeder; reaffirm the new slugs idempotently).
            Setting::updateOrCreate(
                ['slug' => 'collections.charge_generation_day'],
                ['name' => 'Día de generación', 'value' => 5, 'type' => Setting::TYPE_INTEGER],
            );
            Setting::updateOrCreate(
                ['slug' => 'collections.due_soon_days'],
                ['name' => 'Días por vencer', 'value' => 7, 'type' => Setting::TYPE_INTEGER],
            );

            // 3. towers_sections
            Tower::factory()->create(['name' => 'Torre A']);

            // 4. units
            $unit = Unit::factory()->create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            // 5. residents
            UnitUser::factory()->create([
                'unit_id' => $unit->id,
                'user_id' => $treasurer->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
            ]);

            // 6. board_treasurer
            $period = BoardPeriod::create([
                'name' => 'Mesa 2024',
                'start_date' => now()->startOfYear()->toDateString(),
                'end_date' => now()->endOfYear()->toDateString(),
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            BoardMember::create([
                'board_period_id' => $period->id,
                'user_id' => $treasurer->id,
                'board_role_id' => BoardRole::TREASURER,
            ]);

            // 7. bank_account (BankAccountType is a central catalog; BankAccount has no newFactory())
            BankAccount::create([
                'name' => 'Cuenta Principal',
                'bank_account_type_id' => BankAccountType::BANK,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);

            // 8. charge_concepts (charge_concept_category_id NOT NULL — use tenant-seeded category)
            ChargeConcept::create([
                'name' => 'Mantenimiento mensual',
                'code' => 'MANT-001',
                'charge_concept_category_id' => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'),
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'periodicity_id' => Periodicity::MONTHLY,
                'fixed_amount' => 15000,
                'grace_days' => 5,
                'valid_from' => now()->startOfMonth()->toDateString(),
                'is_active' => true,
            ]);

            // Optional not done: catalogs / UnitType (already seeded via TenantCatalogSeeder
            // — wait, it IS seeded. The optional 'catalogs' item checks UnitType::exists(),
            // which will be TRUE after TenantCatalogSeeder. This is fine; the optional item
            // still won't block is_complete since required:false.)
        });
    }
}
