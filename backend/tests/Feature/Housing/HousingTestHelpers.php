<?php

declare(strict_types=1);

namespace Tests\Feature\Housing;

use App\Models\User;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
 * Shared fixture helpers for Housing HTTP feature tests.
 *
 * Mirrors the patterns proven by OrionValidationWiringTest / ClusterScenario:
 * fresh tenant + seeded catalogs, central users with real Sanctum tokens, and
 * per-request guard/tenancy reset so each request behaves like real HTTP.
 */
trait HousingTestHelpers
{
    use WithTenantSetup {
        WithTenantSetup::makeUser as makeTenantUser;
        WithTenantSetup::tenantRequest as baseTenantRequest;
    }

    /**
     * Seed the production role/permission matrix so role-derived tokens carry
     * the same abilities as a real login (AuthController::getPermissions).
     */
    private function seedRolePermissions(): void
    {
        Artisan::call('db:seed', [
            '--class' => ResetPermissionTableSeeder::class,
            '--force' => true,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Housing-specific user factory: accepts an optional role slug and uses a
     * housing-flavoured name. Shadows WithTenantSetup::makeUser intentionally.
     */
    private function makeUser(Tenant $tenant, ?string $roleSlug = null): User
    {
        $user = User::create([
            'name' => 'Housing Test '.Str::random(6),
            'email' => Str::random(12).'@housing-test.mx',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        if ($roleSlug !== null) {
            $user->assignRole($roleSlug);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $user;
    }

    /**
     * Token whose abilities mirror a real login: derived from the user's role
     * permissions unless an explicit ability list is given.
     *
     * @param  array<int, string>|null  $abilities
     */
    private function tokenFor(User $user, ?array $abilities = null): string
    {
        $abilities ??= $user->getPermissionsViaRoles()->pluck('name')->all();

        return $user->createToken('access_token', $abilities)->plainTextToken;
    }

    /**
     * Build a tenant-scoped, token-authenticated request (fresh guards/tenancy).
     * Shadows WithTenantSetup::tenantRequest with identical behaviour.
     */
    private function tenantRequest(string $token, Tenant $tenant): static
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        $this->withToken($token)->withHeader('X-Tenant-Id', $tenant->id);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeUnit(Tenant $tenant, array $attributes = []): Unit
    {
        return $tenant->run(static fn (): Unit => Unit::create(array_merge([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'number' => Str::random(8),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
        ], $attributes)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attachUnitUser(
        Tenant $tenant,
        Unit $unit,
        User $user,
        string $relationshipId = UnitRelationship::OWNER,
        array $attributes = [],
    ): UnitUser {
        return $tenant->run(static fn (): UnitUser => UnitUser::create(array_merge([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'unit_relationship_id' => $relationshipId,
            'is_billing_owner' => $relationshipId === UnitRelationship::OWNER,
            'is_legal_representative' => $relationshipId === UnitRelationship::OWNER,
        ], $attributes)));
    }
}
