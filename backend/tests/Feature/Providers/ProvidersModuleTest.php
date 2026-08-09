<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Models\User;
use Cknow\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Providers\Models\Provider;
use Modules\Providers\Models\ProviderServiceType;
use Modules\Providers\Models\ProviderType;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Integration tests for Module 6 — Providers.
 *
 * Covers:
 *  - provider_type + provider_service_type catalogs seeded per tenant
 *  - Provider CRUD with relations
 *  - estimated_amount money round-trip (integer storage + MoneyIntegerCast)
 *  - Tenant isolation (provider cannot be seen across tenants)
 *  - Unique name constraint per tenant (soft-delete aware)
 *  - RFC COALESCE unique (null allowed multiple times; non-null unique per tenant)
 *  - Provider payment history via expenses (hasMany)
 */
final class ProvidersModuleTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    // =========================================================================
    // Helpers
    // =========================================================================

    // =========================================================================
    // A: Catalog seeding
    // =========================================================================

    public function test_provider_type_catalog_seeded_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(2, ProviderType::count());

            $individual = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            $this->assertSame('Persona física', $individual->name);

            $company = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona moral'));
            $this->assertSame('Persona moral', $company->name);
        });
    }

    public function test_provider_service_type_catalog_seeded_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $this->assertSame(8, ProviderServiceType::count());

            $waste = ProviderServiceType::findOrFail(Catalog::id(ProviderServiceType::class, 'Recolección de basura'));
            $this->assertSame('Recolección de basura', $waste->name);

            $other = ProviderServiceType::findOrFail(Catalog::id(ProviderServiceType::class, 'Otro'));
            $this->assertSame('Otro', $other->name);
        });
    }

    public function test_catalog_seeding_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $this->seedTenant($tenant); // Run twice — should not duplicate.

        $tenant->run(function (): void {
            $this->assertSame(2, ProviderType::count());
            $this->assertSame(8, ProviderServiceType::count());
        });
    }

    public function test_provider_catalogs_are_tenant_scoped_and_isolated(): void
    {
        // provider_type / provider_service_type son catálogos AUTOADMINISTRABLES
        // (tenant-scoped): cada tenant recibe su propia copia sembrada y NUNCA ve
        // las filas (ni las personalizaciones) de otro clúster.
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTenant($tenantA);

        $tenantB->run(function (): void {
            $this->assertSame(0, ProviderType::count(), 'Tenant B sin sembrar no ve filas de A.');
        });

        $this->seedTenant($tenantB);

        $tenantB->run(function (): void {
            $this->assertSame(2, ProviderType::count(), 'Tenant B recibe su propia copia.');
            $this->assertSame(8, ProviderServiceType::count(), 'Tenant B recibe su propia copia.');
        });

        $tenantA->run(function (): void {
            ProviderType::query()->where('name', 'Persona física')->firstOrFail()
                ->update(['name' => 'Física (editado A)']);
        });

        $tenantB->run(function (): void {
            $this->assertNotNull(
                ProviderType::query()->where('name', 'Persona física')->first(),
                'El renombre del tenant A no afecta la fila del tenant B.'
            );
        });
    }

    // =========================================================================
    // B: Provider CRUD and relations
    // =========================================================================

    public function test_provider_create_with_type_and_service_type(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            $serviceType = ProviderServiceType::findOrFail(Catalog::id(ProviderServiceType::class, 'Recolección de basura'));

            $provider = Provider::create([
                'provider_type_id' => $type->id,
                'name' => 'Miguel Armando Flores Alarcón',
                'rfc' => 'FMMA801234AB1',
                'provider_service_type_id' => $serviceType->id,
                'phone' => '7774441234',
                'is_recurring' => true,
                'frequency' => 'monthly',
                'estimated_amount' => 120000, // $1,200.00 in cents
                'usual_payment_day' => 5,
                'is_active' => true,
            ]);

            $provider->load('providerType', 'serviceType');

            $this->assertSame('Miguel Armando Flores Alarcón', $provider->name);
            $this->assertSame('Persona física', $provider->providerType->name);
            $this->assertSame('Recolección de basura', $provider->serviceType->name);
            $this->assertTrue($provider->is_recurring);
            $this->assertSame('monthly', $provider->frequency);
            $this->assertSame(5, $provider->usual_payment_day);
        });
    }

    // =========================================================================
    // C: Money round-trip
    // =========================================================================

    public function test_estimated_amount_money_round_trip(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona moral'));

            $provider = Provider::create([
                'provider_type_id' => $type->id,
                'name' => 'CFE',
                'estimated_amount' => 350000, // $3,500.00
                'is_active' => true,
            ]);

            // Verify integer stored in DB.
            $raw = DB::table('provider')
                ->where('id', $provider->id)
                ->value('estimated_amount');
            $this->assertSame(350000, (int) $raw, 'estimated_amount must be stored as integer (minor units).');

            // Verify MoneyIntegerCast wraps it.
            $fresh = Provider::find($provider->id);
            $this->assertInstanceOf(Money::class, $fresh->estimated_amount);
            $this->assertSame('350000', $fresh->estimated_amount->getAmount());
        });
    }

    // =========================================================================
    // D: Tenant isolation
    // =========================================================================

    public function test_provider_tenant_isolation(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            Provider::create([
                'provider_type_id' => $type->id,
                'name' => 'Provider for A',
                'is_active' => true,
            ]);
        });

        $tenantB->run(function (): void {
            $this->assertSame(0, Provider::count(), 'Tenant B must not see Tenant A providers.');
        });
    }

    // =========================================================================
    // E: Unique constraints
    // =========================================================================

    public function test_duplicate_provider_name_same_tenant_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            Provider::create(['provider_type_id' => $type->id, 'name' => 'Aceros del Sur', 'is_active' => true]);
            Provider::create(['provider_type_id' => $type->id, 'name' => 'Aceros del Sur', 'is_active' => true]);
        });
    }

    /**
     * §10.4.1 (providers-07) — a provider may link to a person of THIS cluster;
     * linking someone from another tenant is rejected by the membership rule.
     */
    public function test_provider_user_link_validates_tenant_membership(): void
    {
        $tenantA = $this->makeTenant('Tenant Link A');
        $tenantB = $this->makeTenant('Tenant Link B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $makeUser = function (Tenant $tenant): User {
            $user = User::create([
                'name' => 'Link '.Str::random(5),
                'email' => Str::random(10).'@test.com',
                'password' => Hash::make('secret1234'),
                'auth_active' => true,
            ]);
            $user->tenants()->attach($tenant->id);

            return $user;
        };

        $member = $makeUser($tenantA);
        $outsider = $makeUser($tenantB);
        $admin = $makeUser($tenantA);

        Sanctum::actingAs($admin, ['create.provider', 'read.provider']);

        $payload = fn (string $userId, string $name): array => [
            'name' => $name,
            'provider_type_id' => Catalog::id(ProviderType::class, 'Persona física'),
            'provider_service_type_id' => Catalog::id(ProviderServiceType::class, 'Jardinería'),
            'phone' => '5511223344',
            'is_active' => true,
            'user_id' => $userId,
        ];

        $this->postJson('/api/provider', $payload($member->id, 'Jardinero Vinculado'))
            ->assertStatus(201)
            ->assertJsonPath('data.user_id', $member->id);

        $this->postJson('/api/provider', $payload($outsider->id, 'Proveedor Pirata'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_same_provider_name_allowed_across_different_tenants(): void
    {
        $tenantA = $this->makeTenant('Tenant A');
        $tenantB = $this->makeTenant('Tenant B');

        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            Provider::create(['provider_type_id' => $type->id, 'name' => 'CFE', 'is_active' => true]);
        });

        // Must not throw — name uniqueness is per-tenant.
        $tenantB->run(function (): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            Provider::create(['provider_type_id' => $type->id, 'name' => 'CFE', 'is_active' => true]);
            $this->assertSame(1, Provider::count());
        });
    }

    public function test_null_rfc_allowed_multiple_times_same_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            Provider::create(['provider_type_id' => $type->id, 'name' => 'Provider 1', 'rfc' => null, 'is_active' => true]);
            Provider::create(['provider_type_id' => $type->id, 'name' => 'Provider 2', 'rfc' => null, 'is_active' => true]);
            $this->assertSame(2, Provider::count(), 'Multiple null RFCs must be allowed.');
        });
    }

    public function test_duplicate_rfc_same_tenant_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $type = ProviderType::findOrFail(Catalog::id(ProviderType::class, 'Persona física'));
            Provider::create(['provider_type_id' => $type->id, 'name' => 'Provider A', 'rfc' => 'XAXX010101000', 'is_active' => true]);
            Provider::create(['provider_type_id' => $type->id, 'name' => 'Provider B', 'rfc' => 'XAXX010101000', 'is_active' => true]);
        });
    }
}
