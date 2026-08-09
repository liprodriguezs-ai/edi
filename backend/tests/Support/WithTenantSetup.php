<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Database\Seeders\TenantDatabaseSeeder;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;

/**
 * Shared tenant-setup helpers extracted from the duplicated private methods
 * that appeared verbatim across ~50 feature-test files.
 *
 * Usage: add `use Tests\Support\WithTenantSetup;` to any TestCase subclass,
 * then include `use WithTenantSetup;` in the class body.
 *
 * Rule: never copy `seedTenant` / `makeTenant` into a new test — always use this trait.
 */
trait WithTenantSetup
{
    /**
     * Create a tenant without firing TenantCreated (suppresses the auto-seeder
     * so tests control exactly when and what gets seeded).
     */
    protected function makeTenant(string $name = 'Test Organization'): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => $name.' '.Str::random(5),
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );
    }

    /**
     * Seed central catalogs (UUID-keyed, no tenant_id) outside the tenant
     * context, then run the per-tenant seeder inside the tenant context.
     */
    protected function seedTenant(Tenant $tenant): void
    {
        // Central catalogs live in the shared DB with no tenant_id.
        (new CatalogSeeder)->run();

        $tenant->run(function (): void {
            Artisan::call('db:seed', [
                '--class' => TenantDatabaseSeeder::class,
                '--force' => true,
            ]);
        });
    }

    /**
     * Create two fully-seeded tenants and a wildcard admin token for tenant A.
     *
     * Returns: [tenantA, tokenA (wildcard '*'), tenantB]
     *
     * @return array{0: Tenant, 1: string, 2: Tenant}
     */
    protected function twoTenants(): array
    {
        $tenantA = $this->makeTenant('Organization A');
        $tenantB = $this->makeTenant('Organization B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        // Wildcard token isolates the test to TenantScope, not permission checks.
        $adminA = $this->makeUser($tenantA);
        $tokenA = $adminA->createToken('admin', ['*'])->plainTextToken;

        return [$tenantA, $tokenA, $tenantB];
    }

    /**
     * Run a callback inside the given tenant's scope and return the result.
     */
    protected function inTenant(Tenant $tenant, callable $callback): mixed
    {
        $result = null;
        $tenant->run(function () use ($callback, &$result): void {
            $result = $callback();
        });

        return $result;
    }

    /**
     * Build a tenant-scoped, token-authenticated test request.
     * Flushes resolved guards so each call re-resolves its bearer token.
     */
    protected function tenantRequest(string $token, Tenant $tenant): static
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->withToken($token)->withHeader('X-Tenant-Id', $tenant->id);

        return $this;
    }

    /**
     * Create a minimal central user and attach them to the given tenant.
     */
    protected function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }
}
