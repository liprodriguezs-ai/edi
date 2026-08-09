<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\User;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Company\Models\Company;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;

/**
 * ClusterScenario — DRY builder for the Kivo7 end-to-end "month in the life"
 * narrative. Builds ONE coherent fraccionamiento ("Privada Los Encinos") with:
 *   - a fully-seeded tenant (catalogs) + a main Company (legal identity)
 *   - real central User rows for every person (app users get a password +
 *     auth_active + the right global Spatie role + a real permission set)
 *   - the physical structure (section, tower, units) with ownership shares
 *   - owner/renter relationships on the units
 *
 * Each acting person LOGS IN for real via POST /api/auth/login, so the Sanctum
 * token they carry has EXACTLY the abilities derived from their Spatie role
 * (AuthController::getPermissions -> getPermissionsViaRoles). Permission gates
 * therefore behave like production.
 *
 * REAL role permissions: the 11 Kivo7 roles now receive realistic per-role
 * permission sets directly from the production seeder
 * (RolePermissionTableSeeder::seedKivo7RolePermissions, run here via
 * ResetPermissionTableSeeder). A real login for e.g. "treasurer" therefore yields
 * a token carrying the abilities its office needs — no test workaround. This is
 * the true integration proof: the narrative + boundary tests pass on the SAME
 * role permissions production ships.
 *
 * @property-read array<string, mixed> $ctx populated by buildScenario()
 */
trait ClusterScenario
{
    use WithTenantSetup;

    /**
     * Spin up the whole fraccionamiento and return a rich context array.
     *
     * @return array<string, mixed>
     */
    private function buildScenario(): array
    {
        // Seed the global modules + permissions + roles + role_has_permissions in
        // the testing DB (RefreshDatabase does not run app seeders). This is the
        // REAL `php artisan db:seed --class=ResetPermissionTableSeeder`, which now
        // assigns each Kivo7 role its production permission set — so the logins
        // below carry the same abilities they would in production. No test-arrange
        // permission granting; this is the genuine integration path.
        Artisan::call('db:seed', [
            '--class' => ResetPermissionTableSeeder::class,
            '--force' => true,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $tenant = $this->makeTenant('Privada Los Encinos');
        $this->seedTenant($tenant);
        $this->configureTenantOperational($tenant);
        $this->makeMainCompany($tenant);

        // ── People (central users) ──────────────────────────────────────────
        // Emails carry a per-build suffix: the central `user` table has a global
        // unique on non-null emails, and the test suite reuses the same DB across
        // methods (no per-method transaction), so fixed emails would collide.
        $b = Str::lower(Str::random(8));
        $emails = [
            'laura' => "laura.fernandez+{$b}@encinos.mx",
            'roberto' => "roberto.mendez+{$b}@encinos.mx",
            'carmen' => "carmen.ruiz+{$b}@encinos.mx",
            'jorge' => "jorge.salinas+{$b}@encinos.mx",
            'ana' => "ana.torres+{$b}@encinos.mx",
            'miguel' => "miguel.soto+{$b}@encinos.mx",
            'patricia' => "patricia.gomez+{$b}@encinos.mx",
            'luis' => "luis.ramirez+{$b}@encinos.mx",
            'pedro' => "pedro.vargas+{$b}@encinos.mx",
            'joseLuis' => "joseluis.hernandez+{$b}@encinos.mx",
        ];

        // Hired admin — full operational access.
        $laura = $this->makeAppUser($tenant, 'Laura Fernández', $emails['laura'], 'administrator');

        // Owners elected to the board. Each carries BOTH their board role (so their
        // real login token has the operational abilities their office needs) and is
        // an owner/resident on a unit. test_02 still drives the board-start endpoint
        // and asserts the SYSTEM grants the Spatie role — the assignment here just
        // makes every test method's abilities coherent without re-running the board
        // flow first (board roles are global Spatie roles; assignRole is idempotent).
        $roberto = $this->makeAppUser($tenant, 'Roberto Méndez', $emails['roberto'], 'president');
        $carmen = $this->makeAppUser($tenant, 'Carmen Ruiz', $emails['carmen'], 'treasurer');
        $jorge = $this->makeAppUser($tenant, 'Jorge Salinas', $emails['jorge'], 'secretary');
        $ana = $this->makeAppUser($tenant, 'Ana Torres', $emails['ana'], 'vocal');
        $miguel = $this->makeAppUser($tenant, 'Miguel Ángel Soto', $emails['miguel'], 'oversight_committee');

        // Plain resident, no board seat.
        $patricia = $this->makeAppUser($tenant, 'Patricia Gómez', $emails['patricia'], 'resident');

        // Renter of Casa 3 (Ana is the owner).
        $luis = $this->makeAppUser($tenant, 'Luis Ramírez', $emails['luis'], 'renter');

        // Employees with app access.
        $pedro = $this->makeAppUser($tenant, 'Pedro Vargas', $emails['pedro'], 'security');
        $joseLuis = $this->makeAppUser($tenant, 'José Luis Hernández', $emails['joseLuis'], 'operations_staff');

        // ── Physical structure + units ──────────────────────────────────────
        $structure = $this->makeStructure($tenant);

        // ── Ownership / residency relationships ─────────────────────────────
        $this->attachUnitUsers($tenant, $structure, [
            'roberto' => $roberto->id,
            'carmen' => $carmen->id,
            'jorge' => $jorge->id,
            'ana' => $ana->id,
            'miguel' => $miguel->id,
            'patricia' => $patricia->id,
            'luis' => $luis->id,
        ]);

        return [
            'tenant' => $tenant,
            'emails' => $emails,
            'users' => [
                'laura' => $laura,
                'roberto' => $roberto,
                'carmen' => $carmen,
                'jorge' => $jorge,
                'ana' => $ana,
                'miguel' => $miguel,
                'patricia' => $patricia,
                'luis' => $luis,
                'pedro' => $pedro,
                'joseLuis' => $joseLuis,
            ],
            'units' => $structure['units'],
            'section_id' => $structure['section_id'],
            'tower_id' => $structure['tower_id'],
        ];
    }

    // =========================================================================
    // Tenant / catalogs / company
    // =========================================================================

    private function configureTenantOperational(Tenant $tenant): void
    {
        $tenant->update([
            'timezone' => 'America/Mexico_City',
            'currency' => 'MXN',
            'type' => 'mixto',
            'uses_ownership_share' => true,
        ]);
    }

    private function makeMainCompany(Tenant $tenant): Company
    {
        $company = null;
        $tenant->run(function () use (&$company, $tenant): void {
            $suffix = Str::lower(Str::random(8));
            $company = Company::create([
                'slug' => 'privada-los-encinos-'.$suffix,
                'domain' => 'los-encinos-'.$suffix,
                'name' => 'Privada Los Encinos',
                'legal_name' => 'Privada Los Encinos A.C.',
                'rfc' => 'PLE260101AB1',
                'fiscal_address' => 'Av. de los Encinos 100, Zapopan, Jalisco',
                'main' => true,
                'is_verified' => true,
                // Company is central (no BelongsToTenant auto-stamp). Stamp tenant_id
                // explicitly so the tenant-scoped main() lookup resolves THIS
                // cluster's company deterministically (production stamps it via
                // CompanyService::ensureMainCompanyForTenant on first login).
                'tenant_id' => $tenant->getTenantKey(),
            ]);
        });

        return $company;
    }

    // =========================================================================
    // Users
    // =========================================================================

    /**
     * Create a central User attached to the tenant, with a password + auth_active
     * and the given global Spatie role assigned.
     */
    private function makeAppUser(Tenant $tenant, string $name, string $email, string $roleSlug): User
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);
        $user->update(['last_active_tenant_id' => $tenant->id]);
        $user->assignRole($roleSlug);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * Log a person in for real and return their access token (carrying their
     * actual permission abilities).
     */
    private function login(string $email, string $password = 'secret1234'): string
    {
        // Login is a public, unauthenticated request: clear any bearer token /
        // tenant header left over from a prior tenant-scoped request so the
        // controller's auth()->attempt() resolves the session guard, not Sanctum.
        // The auth:sanctum middleware calls Auth::shouldUse('sanctum'), which
        // persists the default guard override on the AuthManager; reset it to the
        // configured default so attempt() works against the credential guard.
        $this->flushHeaders();
        // The auth:sanctum middleware from a prior request calls Auth::shouldUse
        // ('sanctum'), which MUTATES config('auth.defaults.guard') to 'sanctum'
        // on the shared container — it persists across requests in the test
        // harness. Restore the session default so the login controller's
        // auth()->attempt() resolves the credential guard (not the Sanctum
        // RequestGuard, which has no attempt()).
        config(['auth.defaults.guard' => 'web']);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);

        return $response->json('data.access_token');
    }

    /**
     * Build a tenant-scoped, token-authenticated request. Flushing the resolved
     * Sanctum guard forces each request to re-resolve its own bearer token, so a
     * test firing multiple requests with different tokens behaves like real HTTP.
     */
    private function as(string $token, Tenant $tenant): static
    {
        // CRITICAL for faithful permission gates. login() calls the controller's
        // auth()->attempt() on the `web` guard, which logs the user into the
        // SESSION and the test client carries the session cookie forward. Sanctum
        // resolves its guard by first checking config('sanctum.guard') = ['web'];
        // if the web/session guard still has that user, Sanctum returns it wrapped
        // in a TransientToken whose can() returns TRUE for EVERY ability — which
        // silently defeats tokenCan() and makes every gate pass. To force Sanctum
        // to authenticate via the REAL bearer PersonalAccessToken (so tokenCan()
        // reflects the user's actual role abilities), we must drop the session:
        //   - flush headers + cookies so no session cookie is sent
        //   - flush the session store and log the web guard out
        //   - reset the default guard to the API/token default
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->app['session']->flush();
        $this->app['auth']->guard('web')->logout();
        config(['auth.defaults.guard' => 'api']);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('api');
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->withToken($token)->withHeader('X-Tenant-Id', $tenant->id);

        return $this;
    }

    // =========================================================================
    // Physical structure
    // =========================================================================

    /**
     * Create section "Manzana A", tower "Torre Norte" (2 floors), and the 6 units.
     * Ownership shares sum to ~100%.
     *
     * @return array{section_id: string, tower_id: string, units: array<string, Unit>}
     */
    private function makeStructure(Tenant $tenant): array
    {
        $out = ['section_id' => '', 'tower_id' => '', 'units' => []];

        $tenant->run(function () use (&$out): void {
            $section = Section::create([
                'name' => 'Manzana A',
                'order' => 0,
            ]);

            $tower = Tower::create([
                'name' => 'Torre Norte',
                'section_id' => $section->id,
                'floors_count' => 2,
                'order' => 0,
            ]);

            $house = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $apartment = UnitType::findOrFail(Catalog::id(UnitType::class, 'Departamento'));
            $active = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

            $units = [];

            // Houses in Manzana A (shares ~16.67% each).
            $units['casa1'] = Unit::create([
                'unit_type_id' => $house->id, 'number' => 'Casa 1',
                'section_id' => $section->id, 'unit_status_id' => $active->id,
                'ownership_share' => 16.67,
            ]);
            $units['casa2'] = Unit::create([
                'unit_type_id' => $house->id, 'number' => 'Casa 2',
                'section_id' => $section->id, 'unit_status_id' => $active->id,
                'ownership_share' => 16.67,
            ]);
            $units['casa3'] = Unit::create([
                'unit_type_id' => $house->id, 'number' => 'Casa 3',
                'section_id' => $section->id, 'unit_status_id' => $active->id,
                'ownership_share' => 16.67,
            ]);

            // Apartments in Torre Norte.
            $units['depto101'] = Unit::create([
                'unit_type_id' => $apartment->id, 'number' => 'Depto 101',
                'tower_id' => $tower->id, 'floor' => 1, 'unit_status_id' => $active->id,
                'ownership_share' => 16.66,
            ]);
            $units['depto102'] = Unit::create([
                'unit_type_id' => $apartment->id, 'number' => 'Depto 102',
                'tower_id' => $tower->id, 'floor' => 1, 'unit_status_id' => $active->id,
                'ownership_share' => 16.66,
            ]);
            $units['depto201'] = Unit::create([
                'unit_type_id' => $apartment->id, 'number' => 'Depto 201',
                'tower_id' => $tower->id, 'floor' => 2, 'unit_status_id' => $active->id,
                'ownership_share' => 16.67,
            ]);

            $out['section_id'] = $section->id;
            $out['tower_id'] = $tower->id;
            $out['units'] = $units;
        });

        return $out;
    }

    /**
     * Wire owners (billing) onto each unit, plus Patricia as resident of 102 and
     * Luis as renter of Casa 3 (renter requires an existing active owner).
     *
     * @param  array{section_id: string, tower_id: string, units: array<string, Unit>}  $structure
     * @param  array<string, string>  $userIds  keyed by person slug -> central user id
     */
    private function attachUnitUsers(Tenant $tenant, array $structure, array $userIds): void
    {
        $tenant->run(function () use ($structure, $userIds): void {
            $owner = UnitRelationship::findOrFail(UnitRelationship::OWNER);
            $renter = UnitRelationship::findOrFail(UnitRelationship::RENTER);
            $units = $structure['units'];

            $makeOwner = function (Unit $unit, string $userId) use ($owner): void {
                UnitUser::create([
                    'unit_id' => $unit->id,
                    'user_id' => $userId,
                    'unit_relationship_id' => $owner->id,
                    'is_billing_owner' => true,
                    'is_legal_representative' => true,
                ]);
            };

            $makeOwner($units['casa1'], $userIds['roberto']);
            $makeOwner($units['casa2'], $userIds['carmen']);
            $makeOwner($units['casa3'], $userIds['ana']);
            $makeOwner($units['depto101'], $userIds['jorge']);
            $makeOwner($units['depto102'], $userIds['patricia']);
            $makeOwner($units['depto201'], $userIds['miguel']);

            // Renter of Casa 3 — requires the active owner (Ana) created above.
            UnitUser::create([
                'unit_id' => $units['casa3']->id,
                'user_id' => $userIds['luis'],
                'unit_relationship_id' => $renter->id,
                'is_billing_owner' => false,
                'is_legal_representative' => false,
            ]);
        });
    }

    /**
     * Resolve a tenant-scoped catalog/board id by running a closure in tenant ctx.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
}
