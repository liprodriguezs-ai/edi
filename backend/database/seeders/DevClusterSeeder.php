<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use GeneaLabs\LaravelModelCaching\Cache\ModelCacheRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
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
use Modules\Collections\Models\ChargeSurchargeRule;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Models\SurchargeMode;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\PaymentService;
use Modules\Company\Models\Company;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Providers\Models\Provider;
use Modules\Providers\Models\ProviderServiceType;
use Modules\Providers\Models\ProviderType;
use Modules\SubscriptionPlan\Database\Seeders\PlanModuleMatrixSeeder;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\EntitlementService;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\Tenant\Database\Seeders\TenantDatabaseSeeder;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Models\TenantPaymentType;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Services\ExpenseService;
use QCH\Core\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\TenantCreated;

/**
 * DevClusterSeeder — ONE fully-seeded residential cluster for local dev & E2E testing.
 *
 * Cluster: "Privada Los Encinos" (Encinos Dev)
 * Domain:  encinos.dev
 * Password: secret1234 (all cluster users)
 *
 * Cluster users & roles (all share password secret1234):
 *   admin@encinos.dev      Laura Fernández       administrator + account.owner
 *   roberto@encinos.dev    Roberto Méndez        president
 *   carmen@encinos.dev     Carmen Ruiz           treasurer
 *   jorge@encinos.dev      Jorge Salinas         secretary
 *   ana@encinos.dev        Ana Torres            vocal
 *   miguel@encinos.dev     Miguel Ángel Soto     oversight_committee
 *   patricia@encinos.dev   Patricia Gómez        resident
 *   luis@encinos.dev       Luis Ramírez          renter
 *   pedro@encinos.dev      Pedro Vargas          security
 *   joseluis@encinos.dev   José Luis Hernández   operations_staff
 *
 * Platform role (NOT part of this cluster — seeded by UsersTableSeeder, cross-tenant):
 *   superadmin@company.web   admin.super   pass: 123abc
 *
 * Run: php artisan db:seed --class=DevClusterSeeder  (via Sail: vendor/bin/sail artisan ...)
 * Idempotent: deletes the fixed tenant (and all its scoped data via cascade) before recreating.
 */
class DevClusterSeeder extends Seeder
{
    // ── Fixed identifiers (stable across re-runs) ──────────────────────────

    /** Fixed UUID for the dev tenant — used for idempotent cleanup. */
    private const TENANT_ID = 'a1b2c3d4-0000-4000-8000-000000000001';

    private const DOMAIN = 'encinos.dev';

    private const PASSWORD = 'secret1234';

    /**
     * Months of financial history to generate (charges, payments, expenses).
     *
     * The treasury/collections dashboards bucket by `paid_at` over a rolling
     * 12-month window, so we seed a full year so every chart renders dense.
     */
    private const MONTHS_OF_HISTORY = 12;

    /** Fixed emails for every dev user. */
    private const EMAILS = [
        'laura' => 'admin@encinos.dev',
        'roberto' => 'roberto@encinos.dev',
        'carmen' => 'carmen@encinos.dev',
        'jorge' => 'jorge@encinos.dev',
        'ana' => 'ana@encinos.dev',
        'miguel' => 'miguel@encinos.dev',
        'patricia' => 'patricia@encinos.dev',
        'luis' => 'luis@encinos.dev',
        'pedro' => 'pedro@encinos.dev',
        'joseLuis' => 'joseluis@encinos.dev',
    ];

    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DevClusterSeeder no corre en producción.');

            return;
        }

        $this->command->info('── DevClusterSeeder: iniciando ──');

        // Flush the tenant-scoped model cache entries left over from a prior run.
        // mike-bronner/laravel-model-caching stores results in Redis DB1 under the
        // "gestioly_database" prefix with per-tenant tags (cachePrefix = 'tenant-{id}').
        // Artisan::call('cache:clear') only flushes the DEFAULT cache connection (DB0),
        // so we must flush Redis DB1 (the 'redis' store / 'cache' connection) directly.
        // This removes all tag entries for our dev tenant so firstOrCreate queries hit
        // the DB and return freshly seeded UUIDs, not stale ones from the deleted tenant.
        $this->flushDevTenantModelCache();

        // ── 0. Cleanup previo ──────────────────────────────────────────────
        $this->cleanup();

        // ── 1. Prerrequisitos centrales (idempotentes) ─────────────────────
        $this->ensureCentralPrerequisites();

        // ── 2. Crear tenant con UUID fijo (sin disparar TenantCreated) ─────
        $tenant = $this->createTenant();

        // ── 3. Seed de catálogos del tenant ───────────────────────────────
        $this->seedTenantCatalogs($tenant);

        // ── 4. Config operacional + plan ──────────────────────────────────
        $this->configureTenant($tenant);

        // ── 5. Admin user (necesario antes de Company para que GlobalObserver tenga actor) ──
        $users = $this->createUsers($tenant);

        // Login the admin so GlobalObserver can stamp audit columns
        Auth::login($users['laura']);

        // ── 6. Company principal ──────────────────────────────────────────
        $this->createMainCompany($tenant);

        // ── 7. Estructura física ──────────────────────────────────────────
        $structure = $this->createStructure($tenant);

        // ── 8. unit_user (owners + renter) ───────────────────────────────
        $this->attachUnitUsers($tenant, $structure, $users);

        // ── 9. Mesa directiva (período actual + cargos) ──────────────────
        $this->createBoard($tenant, $users);

        // ── 10. Tesorería: cuentas bancarias ─────────────────────────────
        $bankAccounts = $this->createBankAccounts($tenant);

        // ── 11. Proveedores (recurrentes + eventual) ─────────────────────
        $providers = $this->createProviders($tenant);

        // ── 12. Cobranza (12 meses de cargos + pagos) ────────────────────
        $this->createCollections($tenant, $structure, $bankAccounts, $users);

        // ── 13. Tesorería: egresos (12 meses, ligados a proveedores) ─────
        $this->createExpenses($tenant, $bankAccounts, $providers, $users);

        Auth::logout();

        // ── 12. Resumen ───────────────────────────────────────────────────
        $this->printSummary($tenant, $bankAccounts);
    }

    // =========================================================================
    // Cache flush (must run BEFORE cleanup and before catalog seeding)
    // =========================================================================

    /**
     * Flush mike-bronner/laravel-model-caching entries for the dev tenant.
     *
     * TenantCatalogModel stores per-tenant cache in Redis DB1 (the 'cache' Redis
     * connection) under keys prefixed with "genealabs:laravel-model-caching:tenant-{id}:".
     * Artisan cache:clear only touches the DEFAULT Redis connection (DB0), so stale
     * UUIDs survive across seeder re-runs and cause FK violations when the tenant's
     * rows are recreated with new UUIDs.
     *
     * We delete ALL keys matching our tenant prefix directly on the cache connection.
     */
    private function flushDevTenantModelCache(): void
    {
        if (! config('laravel-model-caching.enabled')) {
            return;
        }

        try {
            // ModelCacheRepository::make()->invalidateAll() is the only safe way to
            // purge ALL model cache entries from the Redis store (DB1 / 'cache' connection).
            //
            // Why not Artisan::call('cache:clear')?
            //   - It flushes the DEFAULT cache connection (DB0), not the Redis 'cache'
            //     connection (DB1) used by mike-bronner/laravel-model-caching.
            //
            // Why not flush just the dev-tenant keys by pattern (KEYS + DEL)?
            //   - The model cache uses a TAG-based Redis approach: tag ":entries" keys
            //     track which hashed value keys belong to each tag. Deleting the tag
            //     reference keys leaves orphaned value hash keys that the package can
            //     still reach through its internal cache-hit path in some versions.
            //
            // invalidateAll() correctly invalidates the entire model cache store,
            // ensuring no stale UUIDs from previous runs are served to firstOrCreate.
            $this->command->warn('  Flushing model cache (mike-bronner/laravel-model-caching)…');
            ModelCacheRepository::make()->invalidateAll();
        } catch (\Throwable $e) {
            // Non-fatal — if Redis is unreachable, firstOrCreate falls back to DB.
            $this->command->warn('  Advertencia: no se pudo limpiar el model cache: '.$e->getMessage());
        }
    }

    // =========================================================================
    // 0. Cleanup
    // =========================================================================

    /**
     * Delete the fixed dev tenant and ALL its scoped data.
     *
     * Because BaseModel::columns($table, true) uses onDelete('cascade') on the
     * tenant_id FK, a DB-level hard delete (forceDelete) cascades to every
     * tenant-scoped table automatically. Soft-delete alone does NOT trigger the
     * cascade, so we use forceDelete() here.
     *
     * Users are central (no tenant_id FK) — they must be cleaned up explicitly.
     * user_tenant rows cascade from the tenant forceDelete (cascadeOnDelete FK).
     */
    private function cleanup(): void
    {
        // Company is a CENTRAL model (no BelongsToTenant, no cascade FK from tenant).
        // The slug must be unique globally — delete it explicitly before deleting the tenant.
        Company::withTrashed()
            ->where('tenant_id', self::TENANT_ID)
            ->forceDelete();

        $tenant = Tenant::withTrashed()->find(self::TENANT_ID);

        if ($tenant) {
            $this->command->warn('  Borrando tenant dev previo y todos sus datos (cascade)…');

            // Hard-delete the tenant: cascades to every row where
            // BaseModel::columns($table, true) added the tenant_id FK with onDelete('cascade').
            // This covers: unit, charge, charge_batch, charge_concept, payment,
            // expense, bank_account, section, tower, unit_user, etc.
            // Also cascades user_tenant rows (cascadeOnDelete on both FKs).
            $tenant->forceDelete();

            $this->command->info('  Tenant anterior eliminado.');
        }

        // Clean up the fixed user accounts (central table — no cascade from tenant).
        // Must forceDelete because email has a UNIQUE constraint; soft-deleted rows
        // with the same email would block recreation.
        foreach (array_values(self::EMAILS) as $email) {
            $user = User::withTrashed()->where('email', $email)->first();
            if ($user) {
                $user->forceDelete();
            }
        }
    }

    // =========================================================================
    // 1. Central prerequisites
    // =========================================================================

    private function ensureCentralPrerequisites(): void
    {
        $this->command->info('  Verificando catálogos centrales…');

        // Central catalogs (UUID-keyed FSM catalogs — idempotent upsert).
        (new CatalogSeeder())->run();

        // Billing v2 catalog (plans + plan×module price matrix) — idempotent.
        $this->command->info('  Sembrando catálogo de facturación (billing v2)…');
        $this->call(PlanModuleMatrixSeeder::class);
    }

    // =========================================================================
    // 2. Create tenant
    // =========================================================================

    private function createTenant(): Tenant
    {
        $this->command->info('  Creando tenant "Privada Los Encinos" (UUID fijo)…');

        // Suppress TenantCreated so we control the per-tenant seeding manually.
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => self::TENANT_ID,
                'name' => 'Privada Los Encinos (Dev)',
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    // =========================================================================
    // 3. Per-tenant catalog seed
    // =========================================================================

    private function seedTenantCatalogs(Tenant $tenant): void
    {
        $this->command->info('  Sembrando catálogos del tenant…');

        // Central catalogs (idempotent).
        (new CatalogSeeder())->run();

        // Per-tenant catalogs: unit_type, unit_status, bank_account_purpose, etc.
        // Invoke the nested seeder directly (NOT via Artisan::call): a nested
        // Artisan application hijacks this seeder's OutputStyle, silently
        // swallowing every $this->command->info() that follows — including the
        // final credentials summary. $this->call() preserves the live output.
        $tenant->run(function (): void {
            $this->call(TenantDatabaseSeeder::class);
        });
    }

    // =========================================================================
    // 4. Operational config + billing v2 access grant
    // =========================================================================

    private function configureTenant(Tenant $tenant): void
    {
        $this->command->info('  Configurando tenant (timezone, currency, plan…)…');

        // Billing v2: access is granted by a tenant_entitlement, NOT a legacy
        // subscription_plan. The dev cluster gets FULL, PERPETUAL access via a
        // super_admin_free entitlement on the smallest base plan — no Stripe, never
        // expires → getSubscriptionState() returns 'active' (is_blocked=false) for
        // every user, so nobody hits the "contact admin" dead end.
        $planPricing = app(PlanPricingService::class);
        $baseModule = $planPricing->baseModule();
        $plan = $planPricing->planForUnits(null);

        $tenant->update([
            'timezone' => 'America/Mexico_City',
            'currency' => 'MXN',
            'type' => 'mixto',
            'uses_ownership_share' => true,
            'tenant_payment_type_id' => TenantPaymentType::SUBSCRIPTION,
            'billing_package_id' => $plan->id,
        ]);

        app(EntitlementService::class)->grant(
            $tenant,
            $baseModule,
            $plan,
            TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            ['metadata' => ['reason' => 'dev_cluster']],
        );
    }

    // =========================================================================
    // 5. Users
    // =========================================================================

    /**
     * @return array<string, User>
     */
    private function createUsers(Tenant $tenant): array
    {
        $this->command->info('  Creando usuarios (10)…');

        $definitions = [
            'laura' => ['name' => 'Laura Fernández',          'role' => 'administrator'],
            'roberto' => ['name' => 'Roberto Méndez',           'role' => 'president'],
            'carmen' => ['name' => 'Carmen Ruiz',              'role' => 'treasurer'],
            'jorge' => ['name' => 'Jorge Salinas',            'role' => 'secretary'],
            'ana' => ['name' => 'Ana Torres',               'role' => 'vocal'],
            'miguel' => ['name' => 'Miguel Ángel Soto',        'role' => 'oversight_committee'],
            'patricia' => ['name' => 'Patricia Gómez',           'role' => 'resident'],
            'luis' => ['name' => 'Luis Ramírez',             'role' => 'renter'],
            'pedro' => ['name' => 'Pedro Vargas',             'role' => 'security'],
            'joseLuis' => ['name' => 'José Luis Hernández',      'role' => 'operations_staff'],
        ];

        $users = [];

        foreach ($definitions as $key => $def) {
            $user = User::create([
                'name' => $def['name'],
                'email' => self::EMAILS[$key],
                'password' => Hash::make(self::PASSWORD),
                'auth_active' => true,
            ]);

            $user->tenants()->attach($tenant->id);
            $user->update(['last_active_tenant_id' => $tenant->id]);
            $user->assignRole($def['role']);

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $users[$key] = $user;
        }

        // The dev cluster must have a real account owner, otherwise
        // getSubscriptionState() reports no owner and (on a Stripe plan) blocks
        // everyone. Grant account.owner to the admin (laura) ADDITIVELY — she
        // keeps her administrator role. assignRole is idempotent.
        $users['laura']->assignRole(Role::SLUG_ACCOUNT_OWNER);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $users;
    }

    // =========================================================================
    // 6. Main Company
    // =========================================================================

    private function createMainCompany(Tenant $tenant): Company
    {
        $company = null;

        $tenant->run(function () use (&$company, $tenant): void {
            $company = Company::create([
                'slug' => 'privada-los-encinos-dev',
                'domain' => self::DOMAIN,
                'name' => 'Privada Los Encinos',
                'legal_name' => 'Privada Los Encinos A.C.',
                'rfc' => 'PLE260101ABC',
                'fiscal_address' => 'Av. de los Encinos 100, Zapopan, Jalisco, 45010',
                'main' => true,
                'is_verified' => true,
                // Company is a CENTRAL model (no BelongsToTenant auto-stamp).
                // Stamp tenant_id explicitly so scopeMain() resolves correctly.
                'tenant_id' => $tenant->getTenantKey(),
            ]);
        });

        return $company;
    }

    // =========================================================================
    // 7. Physical structure
    // =========================================================================

    /**
     * @return array{section_id: string, tower_id: string, units: array<string, Unit>}
     */
    private function createStructure(Tenant $tenant): array
    {
        $this->command->info('  Creando estructura física (1 sección, 1 torre, 6 unidades)…');

        $out = ['section_id' => '', 'tower_id' => '', 'units' => []];

        $tenant->run(function () use (&$out): void {
            $section = Section::create([
                'name' => 'Manzana A',
                'order' => 0,
            ]);

            // Tower name is JUST "Norte"; Unit::buildFullIdentifier prepends "Torre ".
            $tower = Tower::create([
                'name' => 'Norte',
                'section_id' => $section->id,
                'floors_count' => 2,
                'order' => 0,
            ]);

            // Resolve tenant-scoped catalogs by name (TenantScope is active inside run()).
            $houseType = UnitType::where('name', 'Casa')->firstOrFail();
            $apartmentType = UnitType::where('name', 'Departamento')->firstOrFail();
            $activeStatus = UnitStatus::where('name', 'Activa')->firstOrFail();

            $units = [];

            // Houses in Manzana A — ownership_share sums to ~100 %.
            // number is JUST the number; Unit::buildFullIdentifier prepends the type ("Casa ").
            $units['casa1'] = Unit::create([
                'unit_type_id' => $houseType->id,
                'number' => '1',
                'section_id' => $section->id,
                'unit_status_id' => $activeStatus->id,
                'ownership_share' => 16.67,
            ]);

            $units['casa2'] = Unit::create([
                'unit_type_id' => $houseType->id,
                'number' => '2',
                'section_id' => $section->id,
                'unit_status_id' => $activeStatus->id,
                'ownership_share' => 16.67,
            ]);

            $units['casa3'] = Unit::create([
                'unit_type_id' => $houseType->id,
                'number' => '3',
                'section_id' => $section->id,
                'unit_status_id' => $activeStatus->id,
                'ownership_share' => 16.67,
            ]);

            // Apartments in Torre Norte — number is JUST the number; the accessor
            // composes "Torre Norte · Piso N · Depto NNN".
            $units['depto101'] = Unit::create([
                'unit_type_id' => $apartmentType->id,
                'number' => '101',
                'tower_id' => $tower->id,
                'floor' => 1,
                'unit_status_id' => $activeStatus->id,
                'ownership_share' => 16.66,
            ]);

            $units['depto102'] = Unit::create([
                'unit_type_id' => $apartmentType->id,
                'number' => '102',
                'tower_id' => $tower->id,
                'floor' => 1,
                'unit_status_id' => $activeStatus->id,
                'ownership_share' => 16.66,
            ]);

            $units['depto201'] = Unit::create([
                'unit_type_id' => $apartmentType->id,
                'number' => '201',
                'tower_id' => $tower->id,
                'floor' => 2,
                'unit_status_id' => $activeStatus->id,
                'ownership_share' => 16.67,
            ]);

            $out['section_id'] = $section->id;
            $out['tower_id'] = $tower->id;
            $out['units'] = $units;
        });

        return $out;
    }

    // =========================================================================
    // 8. Unit → User relationships
    // =========================================================================

    /**
     * @param  array{section_id: string, tower_id: string, units: array<string, Unit>}  $structure
     * @param  array<string, User>  $users
     */
    private function attachUnitUsers(Tenant $tenant, array $structure, array $users): void
    {
        $this->command->info('  Vinculando usuarios a unidades (owners + renter)…');

        $tenant->run(function () use ($structure, $users): void {
            $ownerRelationship = UnitRelationship::findOrFail(UnitRelationship::OWNER);
            $renterRelationship = UnitRelationship::findOrFail(UnitRelationship::RENTER);

            $makeOwner = static function (Unit $unit, User $user) use ($ownerRelationship): void {
                UnitUser::create([
                    'unit_id' => $unit->id,
                    'user_id' => $user->id,
                    'unit_relationship_id' => $ownerRelationship->id,
                    'is_billing_owner' => true,
                    'is_legal_representative' => true,
                ]);
            };

            $units = $structure['units'];

            $makeOwner($units['casa1'], $users['roberto']);
            $makeOwner($units['casa2'], $users['carmen']);
            $makeOwner($units['casa3'], $users['ana']);
            $makeOwner($units['depto101'], $users['jorge']);
            $makeOwner($units['depto102'], $users['patricia']);
            $makeOwner($units['depto201'], $users['miguel']);

            // Luis rents Casa 3 (Ana is the owner).
            UnitUser::create([
                'unit_id' => $units['casa3']->id,
                'user_id' => $users['luis']->id,
                'unit_relationship_id' => $renterRelationship->id,
                'is_billing_owner' => false,
                'is_legal_representative' => false,
            ]);
        });
    }

    // =========================================================================
    // 9. Bank accounts
    // =========================================================================

    /**
     * @return array<string, BankAccount>
     */
    private function createBankAccounts(Tenant $tenant): array
    {
        $this->command->info('  Creando cuentas bancarias (caja + bancaria)…');

        $accounts = [];

        $tenant->run(function () use (&$accounts): void {
            // Resolve tenant-scoped BankAccountPurpose by name.
            $purposeOperating = BankAccountPurpose::where('name', 'Operación')->first()
                ?? BankAccountPurpose::first();

            // CASH account (caja chica).
            $accounts['caja'] = BankAccount::create([
                'name' => 'Caja Chica Los Encinos',
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => $purposeOperating?->id,
                'currency' => 'MXN',
                'initial_balance' => 5000_00,  // $5,000.00 MXN
                'is_primary' => false,
                'is_active' => true,
                'notes' => 'Caja chica para gastos menores (seeder de desarrollo)',
            ]);

            // Bank account (cuenta bancaria principal).
            $accounts['banco'] = BankAccount::create([
                'name' => 'BBVA Los Encinos',
                'bank_account_type_id' => BankAccountType::BANK,
                'bank_account_purpose_id' => $purposeOperating?->id,
                'bank' => 'BBVA',
                'account_number' => '0123456789',
                'clabe' => '012345678901234567',
                'currency' => 'MXN',
                'initial_balance' => 50000_00,  // $50,000.00 MXN
                'is_primary' => true,
                'is_active' => true,
                'notes' => 'Cuenta bancaria principal (seeder de desarrollo)',
            ]);
        });

        return $accounts;
    }

    // =========================================================================
    // 10. Collections
    // =========================================================================

    /**
     * @param  array{section_id: string, tower_id: string, units: array<string, Unit>}  $structure
     * @param  array<string, BankAccount>  $bankAccounts
     * @param  array<string, User>  $users
     */
    private function createCollections(
        Tenant $tenant,
        array $structure,
        array $bankAccounts,
        array $users,
    ): void {
        $this->command->info('  Creando cobranza (conceptos, lotes, cargos, pagos)…');

        $tenant->run(function () use ($structure, $bankAccounts, $users): void {
            // Resolve tenant-scoped ChargeConceptCategory by name.
            $category = ChargeConceptCategory::where('name', 'Ordinaria')->first()
                ?? ChargeConceptCategory::first();

            // ── Charge concept: mantenimiento mensual (IDENTIDAD) ────────────
            $concept = ChargeConcept::create([
                'code' => 'MANT-001',
                'name' => 'Mantenimiento Mensual',
                'description' => 'Cuota mensual de mantenimiento de áreas comunes',
                'charge_concept_category_id' => $category?->id,
                'is_active' => true,
            ]);

            // ── Charge policy: configuración de cobro del concepto ───────────
            // FIXED_REQUIRED $1,500, gracia 5 días, MONTHLY, con una ventana de
            // vigencia que cubre con holgura los MONTHS_OF_HISTORY meses sembrados
            // hacia atrás (más un año hacia adelante para los lotes futuros).
            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => 'Cuota mensual estándar',
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => 1500_00,  // $1,500.00 MXN
                'grace_days' => 5,
                'periodicity_id' => Periodicity::MONTHLY,
                'valid_from' => now()->subMonths(self::MONTHS_OF_HISTORY + 1)->startOfMonth()->toDateString(),
                'valid_to' => now()->addYear()->endOfMonth()->toDateString(),
                'is_active' => true,
            ]);

            // ── Late-fee schedule: 5% del saldo vencido por cada mes de mora ──
            // (recargo moratorio de ejemplo desde el primer mes vencido).
            ChargeSurchargeRule::create([
                'charge_policy_id' => $policy->id,
                'from_month' => 1,
                'surcharge_mode_id' => SurchargeMode::PERCENT,
                'surcharge_value' => 500, // 500 basis points = 5.00%
                'recurrence' => ChargeSurchargeRule::RECURRENCE_PER_MONTH_OVERDUE,
                'cap_amount' => null,
                'is_active' => true,
            ]);

            $generationService = app(ChargeGenerationService::class);
            $paymentService = app(PaymentService::class);

            // ── Plantilla inicial (is_initial = true): SOLO config, SIN cargos ──
            // Modelo nuevo: el lote is_initial es una PLANTILLA PURA (DRAFT, sin cargos,
            // month = null) que el cron clona cada período. Los cargos nacen SIEMPRE en los
            // clones activados (is_initial = false) de abajo, NUNCA en la plantilla.
            ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'is_initial' => true,
                'title' => 'Mantenimiento (plantilla)',
                'description' => 'Plantilla de cobro mensual — el cron la clona cada período (dev)',
                'year' => now()->year,
                'month' => null,
                'target_type' => 'all',
                'target_selector' => null,
                'issued_at' => now()->startOfMonth()->toDateString(),
                'due_date' => now()->endOfMonth()->toDateString(),
                'charge_batch_status_id' => ChargeBatchStatus::DRAFT,
                'progress_pct' => 0,
            ]);

            // ── 12 meses de lotes mensuales + pagos ──────────────────────────
            // Cada mes hacia atrás genera un lote (clon de la plantilla) y se
            // registran los pagos según un plan por mes (paymentPlan): los meses
            // viejos quedan casi 100% cobrados y los recientes acumulan algo de
            // morosidad y cargos pendientes, dibujando una curva realista en los
            // tableros de cobranza, tesorería y resultados.
            $units = $structure['units'];
            $bankId = $bankAccounts['banco']->id;
            $cashId = PaymentMethod::CASH;
            $transferId = PaymentMethod::TRANSFER;

            // Unit order MUST match the per-month action arrays in paymentPlan().
            $unitOrder = ['casa1', 'casa2', 'casa3', 'depto101', 'depto102', 'depto201'];
            $payerByUnit = [
                'casa1' => 'roberto',
                'casa2' => 'carmen',
                'casa3' => 'ana',
                'depto101' => 'jorge',
                'depto102' => 'patricia',
                'depto201' => 'miguel',
            ];
            $plan = $this->paymentPlan();

            for ($monthsAgo = self::MONTHS_OF_HISTORY - 1; $monthsAgo >= 0; $monthsAgo--) {
                $when = now()->subMonths($monthsAgo);

                $batch = ChargeBatch::create([
                    'charge_policy_id' => $policy->id,
                    'title' => 'Mantenimiento '.$when->format('F Y'),
                    'description' => 'Cobro mensual de mantenimiento (dev seeder)',
                    'year' => $when->year,
                    'month' => $when->month,
                    'target_type' => 'all',
                    'target_selector' => null,
                    'issued_at' => $when->copy()->startOfMonth()->toDateString(),
                    'due_date' => $when->copy()->endOfMonth()->toDateString(),
                    'charge_batch_status_id' => ChargeBatchStatus::DRAFT,
                    'progress_pct' => 0,
                ]);

                $generationService->activateBatch($batch);

                $actions = $plan[$monthsAgo];

                foreach ($unitOrder as $index => $unitKey) {
                    $action = $actions[$index];

                    if ($action === 'none') {
                        continue;
                    }

                    // Pago el día 8 del mes del lote, nunca en el futuro (el lote del
                    // mes actual vence a fin de mes pero el residente paga anticipado).
                    $paidAt = $when->copy()->startOfMonth()->addDays(7);
                    if ($paidAt->isFuture()) {
                        $paidAt = now();
                    }

                    $paymentService->register([
                        'unit_id' => $units[$unitKey]->id,
                        'paid_at' => $paidAt->toDateString(),
                        'total_amount' => $action === 'full' ? 1500_00 : 750_00,
                        'payment_method_id' => $index % 2 === 0 ? $cashId : $transferId,
                        'bank_account_id' => $bankId,
                        'payer_user_id' => $users[$payerByUnit[$unitKey]]->id,
                        'notes' => $action === 'full' ? 'Pago completo (dev seeder)' : 'Pago parcial (dev seeder)',
                    ], true);  // $silent=true: no PDF job, no notification
                }
            }
        });
    }

    // =========================================================================
    // 11. Expenses
    // =========================================================================

    /**
     * @param  array<string, BankAccount>  $bankAccounts
     * @param  array<string, Provider>  $providers
     * @param  array<string, User>  $users
     */
    private function createExpenses(
        Tenant $tenant,
        array $bankAccounts,
        array $providers,
        array $users,
    ): void {
        $this->command->info('  Creando egresos de tesorería (12 meses, por proveedor/categoría)…');

        $tenant->run(function () use ($bankAccounts, $providers, $users): void {
            $expenseService = app(ExpenseService::class);

            $bankId = $bankAccounts['banco']->id;
            $cashId = PaymentMethod::CASH;
            $transferId = PaymentMethod::TRANSFER;
            $providerBenef = BeneficiaryType::PROVIDER;
            $otherBenef = BeneficiaryType::OTHER;

            // Resolve tenant-scoped ExpenseCategory by name.
            $catSalarios = ExpenseCategory::where('name', 'Salarios')->first();
            $catServicios = ExpenseCategory::where('name', 'Servicios públicos')->first();
            $catMantenimiento = ExpenseCategory::where('name', 'Mantenimiento')->first();
            $catInsumos = ExpenseCategory::where('name', 'Insumos')->first();
            $catImprevistos = ExpenseCategory::where('name', 'Imprevistos')->first();

            for ($monthsAgo = self::MONTHS_OF_HISTORY - 1; $monthsAgo >= 0; $monthsAgo--) {
                $when = now()->subMonths($monthsAgo);
                $year = $when->year;
                $month = $when->month;
                $label = $when->format('F Y');

                // Día D del mes del egreso, nunca en el futuro.
                $on = static function (int $day) use ($when) {
                    $date = $when->copy()->startOfMonth()->addDays($day - 1);

                    return ($date->isFuture() ? now() : $date)->toDateString();
                };

                // 1) Jardinería — proveedor recurrente, todos los meses.
                $expenseService->register([
                    'concept' => 'Jardinería — '.$label,
                    'description' => 'Mantenimiento mensual de áreas verdes (dev seeder)',
                    'paid_at' => $on(5),
                    'amount' => 3500_00,
                    'expense_category_id' => $catSalarios?->id,
                    'beneficiary_type_id' => $providerBenef,
                    'provider_id' => $providers['jardineria']->id,
                    'year' => $year,
                    'month' => $month,
                    'payment_method_id' => $transferId,
                    'bank_account_id' => $bankId,
                    'authorized_by_user_id' => $users['carmen']->id,
                ], true);

                // 2) Energía eléctrica — proveedor recurrente, todos los meses.
                $expenseService->register([
                    'concept' => 'Energía eléctrica — '.$label,
                    'description' => 'Recibo de luz de áreas comunes (dev seeder)',
                    'paid_at' => $on(12),
                    'amount' => 1100_00 + (($month % 4) * 80_00),
                    'expense_category_id' => $catServicios?->id,
                    'beneficiary_type_id' => $providerBenef,
                    'provider_id' => $providers['luz']->id,
                    'year' => $year,
                    'month' => $month,
                    'payment_method_id' => $transferId,
                    'bank_account_id' => $bankId,
                    'authorized_by_user_id' => $users['carmen']->id,
                ], true);

                // 3) Servicio de agua — proveedor recurrente bimestral (meses pares).
                if ($month % 2 === 0) {
                    $expenseService->register([
                        'concept' => 'Servicio de agua — '.$label,
                        'description' => 'Pago bimestral SIAPA (dev seeder)',
                        'paid_at' => $on(15),
                        'amount' => 1300_00,
                        'expense_category_id' => $catServicios?->id,
                        'beneficiary_type_id' => $providerBenef,
                        'provider_id' => $providers['agua']->id,
                        'year' => $year,
                        'month' => $month,
                        'payment_method_id' => $transferId,
                        'bank_account_id' => $bankId,
                        'authorized_by_user_id' => $users['laura']->id,
                    ], true);
                }

                // 4) Insumos de mantenimiento — proveedor eventual, monto variable.
                $maintCategory = ($month % 2 === 0) ? $catMantenimiento : $catInsumos;
                $expenseService->register([
                    'concept' => 'Insumos de mantenimiento — '.$label,
                    'description' => 'Pintura, herramienta y materiales (dev seeder)',
                    'paid_at' => $on(20),
                    'amount' => 600_00 + ((($month * 137) % 9) * 100_00),
                    'expense_category_id' => $maintCategory?->id,
                    'beneficiary_type_id' => $providerBenef,
                    'provider_id' => $providers['ferreteria']->id,
                    'year' => $year,
                    'month' => $month,
                    'payment_method_id' => $cashId,
                    'bank_account_id' => $bankId,
                    'authorized_by_user_id' => $users['laura']->id,
                ], true);

                // 5) Imprevisto ocasional (cada ~4 meses) — sin proveedor.
                if ($monthsAgo % 4 === 1) {
                    $expenseService->register([
                        'concept' => 'Gasto imprevisto — '.$label,
                        'description' => 'Reparación no presupuestada (dev seeder)',
                        'paid_at' => $on(22),
                        'amount' => 900_00,
                        'expense_category_id' => $catImprevistos?->id,
                        'beneficiary_type_id' => $otherBenef,
                        'beneficiary_text' => 'Servicio eventual',
                        'year' => $year,
                        'month' => $month,
                        'payment_method_id' => $cashId,
                        'bank_account_id' => $bankId,
                        'authorized_by_user_id' => $users['laura']->id,
                    ], true);
                }
            }
        });
    }

    // =========================================================================
    // Board (mesa directiva) — current period with assigned roles
    // =========================================================================

    /**
     * Open a CURRENT board period and assign the five governance roles.
     *
     * Beyond seeding realistic data, this completes the onboarding checklist
     * item `board_treasurer` (a CURRENT period with a TREASURER member), so the
     * admin lands straight on the dashboard instead of the onboarding wizard.
     *
     * @param  array<string, User>  $users
     */
    private function createBoard(Tenant $tenant, array $users): void
    {
        $this->command->info('  Creando mesa directiva (período actual + cargos)…');

        $tenant->run(function () use ($users): void {
            $period = BoardPeriod::create([
                'name' => 'Mesa Directiva '.now()->subMonths(3)->year.'–'.now()->addMonths(9)->year,
                'start_date' => now()->subMonths(3)->startOfMonth()->toDateString(),
                'end_date' => now()->addMonths(9)->endOfMonth()->toDateString(),
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            // Each user already holds the matching Spatie role (createUsers); the
            // board member row links them to the governance position for this period.
            $roleByUser = [
                'roberto' => BoardRole::PRESIDENT,
                'carmen' => BoardRole::TREASURER,
                'jorge' => BoardRole::SECRETARY,
                'ana' => BoardRole::VOCAL,
                'miguel' => BoardRole::OVERSIGHT_COMMITTEE,
            ];

            foreach ($roleByUser as $userKey => $boardRoleId) {
                BoardMember::create([
                    'board_period_id' => $period->id,
                    'user_id' => $users[$userKey]->id,
                    'board_role_id' => $boardRoleId,
                ]);
            }
        });
    }

    // =========================================================================
    // Providers — recurring suppliers + one-off vendor
    // =========================================================================

    /**
     * Create the cluster's service providers. Expenses link to these so the
     * Gastos dashboard renders the by-provider breakdown and recurring share.
     *
     * @return array<string, Provider>
     */
    private function createProviders(Tenant $tenant): array
    {
        $this->command->info('  Creando proveedores (recurrentes + eventual)…');

        $providers = [];

        $tenant->run(function () use (&$providers): void {
            // Tenant-scoped provider catalogs.
            $moral = ProviderType::where('name', 'Persona moral')->first();
            $fisica = ProviderType::where('name', 'Persona física')->first();
            $stJardineria = ProviderServiceType::where('name', 'Jardinería')->first();
            $stServicios = ProviderServiceType::where('name', 'Servicios públicos')->first();
            $stInsumos = ProviderServiceType::where('name', 'Insumos y suministros')->first();

            $providers['jardineria'] = Provider::create([
                'provider_type_id' => $moral?->id,
                'provider_service_type_id' => $stJardineria?->id,
                'name' => 'Jardines del Valle',
                'contact_person' => 'Ernesto García',
                'is_recurring' => true,
                'estimated_amount' => 3500_00,
                'usual_payment_day' => 5,
                'is_active' => true,
            ]);

            $providers['luz'] = Provider::create([
                'provider_type_id' => $moral?->id,
                'provider_service_type_id' => $stServicios?->id,
                'name' => 'CFE',
                'is_recurring' => true,
                'estimated_amount' => 1200_00,
                'usual_payment_day' => 12,
                'is_active' => true,
            ]);

            $providers['agua'] = Provider::create([
                'provider_type_id' => $moral?->id,
                'provider_service_type_id' => $stServicios?->id,
                'name' => 'SIAPA',
                'is_recurring' => true,
                'estimated_amount' => 1300_00,
                'usual_payment_day' => 15,
                'is_active' => true,
            ]);

            $providers['ferreteria'] = Provider::create([
                'provider_type_id' => $fisica?->id,
                'provider_service_type_id' => $stInsumos?->id,
                'name' => 'Ferretería El Martillo',
                'contact_person' => 'Martín López',
                'is_recurring' => false,
                'is_active' => true,
            ]);
        });

        return $providers;
    }

    // =========================================================================
    // Payment plan — per-month, per-unit collection profile
    // =========================================================================

    /**
     * Per-month payment actions, one entry per `MONTHS_OF_HISTORY` index
     * (0 = current month). Each array maps 1:1 to the unit order
     * [casa1, casa2, casa3, depto101, depto102, depto201].
     *
     * 'full' → pays the whole $1,500 (charge COMPLETED)
     * 'partial' → pays half, $750 (charge PARTIAL)
     * 'none' → does not pay (past charge becomes OVERDUE; current stays pending)
     *
     * Older months are nearly fully collected; recent months accumulate a bit
     * of delinquency so aging/collection-rate charts tell a realistic story.
     * Casa 1 always pays; Casa 3 (rented) is the chronic late payer.
     *
     * @return array<int, array<int, string>>
     */
    private function paymentPlan(): array
    {
        // Every month keeps income above the ~$6,300 monthly expense run-rate so
        // the P&L stays net-positive. Casa 3 (rented) is the serial debtor across
        // the last three closed months → its charges age into the 30/60/90-day
        // buckets, giving the morosidad/aging report a realistic spread without
        // dragging any single month into the red.
        return [
            11 => ['full', 'full', 'full', 'full', 'full', 'full'],
            10 => ['full', 'full', 'full', 'full', 'full', 'full'],
            9 => ['full', 'full', 'full', 'full', 'full', 'partial'],
            8 => ['full', 'full', 'full', 'full', 'full', 'full'],
            7 => ['full', 'full', 'full', 'full', 'full', 'full'],
            6 => ['full', 'full', 'full', 'partial', 'full', 'full'],
            5 => ['full', 'full', 'full', 'full', 'full', 'full'],
            4 => ['full', 'full', 'full', 'full', 'full', 'full'],
            3 => ['full', 'full', 'none', 'full', 'full', 'full'],
            2 => ['full', 'full', 'none', 'full', 'full', 'full'],
            1 => ['full', 'full', 'none', 'full', 'full', 'full'],
            0 => ['full', 'full', 'partial', 'full', 'full', 'full'],
        ];
    }

    // =========================================================================
    // 12. Summary
    // =========================================================================

    /**
     * @param  array<string, BankAccount>  $bankAccounts
     */
    private function printSummary(Tenant $tenant, array $bankAccounts): void
    {
        // Refresh bank accounts to reflect balance mutations.
        $banco = $bankAccounts['banco']->fresh();
        $caja = $bankAccounts['caja']->fresh();

        $this->command->newLine();
        $this->command->info('╔══════════════════════════════════════════════════════════╗');
        $this->command->info('║         DevClusterSeeder — Resumen                       ║');
        $this->command->info('╠══════════════════════════════════════════════════════════╣');
        $this->command->info('║  Tenant ID : '.self::TENANT_ID);
        $this->command->info('║  Dominio   : '.self::DOMAIN);
        $this->command->info('║  Password  : '.self::PASSWORD.'  (todos los usuarios)');
        $this->command->info('╠══════════════════════════════════════════════════════════╣');
        $this->command->info('║  Usuarios                                                ║');

        foreach (self::EMAILS as $key => $email) {
            $roles = [
                'laura' => 'administrator + account.owner',
                'roberto' => 'president',
                'carmen' => 'treasurer',
                'jorge' => 'secretary',
                'ana' => 'vocal',
                'miguel' => 'oversight_committee',
                'patricia' => 'resident',
                'luis' => 'renter',
                'pedro' => 'security',
                'joseLuis' => 'operations_staff',
            ];
            $this->command->info("║    [{$roles[$key]}] {$email}");
        }

        $this->command->info('╠══════════════════════════════════════════════════════════╣');
        $this->command->info('║  Plataforma (rol cross-tenant — NO pertenece al clúster) ║');
        $this->command->info('║    [admin.super] superadmin@company.web   pass: 123abc');
        $this->command->info('║    Sembrado por UsersTableSeeder, no por este seeder.');
        $this->command->info('╠══════════════════════════════════════════════════════════╣');

        $tenant->run(function () use ($banco, $caja): void {
            $unitCount = Unit::count();
            $chargeCount = Charge::count();
            $paymentCount = Payment::count();
            $expenseCount = Expense::count();

            $bancoBalance = $banco ? number_format((int) $banco->getRawOriginal('current_balance') / 100, 2) : '?';
            $cajaBalance = $caja ? number_format((int) $caja->getRawOriginal('current_balance') / 100, 2) : '?';

            $this->command->info("║  Unidades   : {$unitCount}");
            $this->command->info("║  Cargos     : {$chargeCount}  (mezcla de estados)");
            $this->command->info("║  Pagos      : {$paymentCount}");
            $this->command->info("║  Egresos    : {$expenseCount}");
            $this->command->info("║  Banco BBVA : \${$bancoBalance} MXN");
            $this->command->info("║  Caja chica : \${$cajaBalance} MXN");
        });

        $this->command->info('╚══════════════════════════════════════════════════════════╝');
        $this->command->newLine();
        $this->command->info('  Listo. Ejecuta de nuevo para recrear datos frescos.');
    }
}
