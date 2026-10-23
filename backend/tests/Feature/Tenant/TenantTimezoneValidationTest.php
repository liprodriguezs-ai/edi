<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * tenant.timezone drives every civil "today" of the tenant (TenantTime), so it
 * must be a real IANA identifier on provision AND on update.
 */
final class TenantTimezoneValidationTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TenantCreated::class]);
    }

    protected function tearDown(): void
    {
        $this->cleanBillingTables();
        DB::table('user_tenant')->delete();
        DB::table('tenant')->delete();
        DB::table('user')->delete();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function provisionBody(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Centro '.Str::random(5),
            'payment_method' => Tenant::PAYMENT_METHOD_CARD,
            'provision_mode' => 'trial',
        ], $overrides);
    }

    public function test_provision_rejects_a_non_iana_timezone_in_spanish(): void
    {
        $this->seedPlanMatrix();

        $this->withToken($this->superAdminToken())
            ->postJson('/api/tenant/provision', $this->provisionBody(['timezone' => 'Mexico/Nowhere']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone' => 'La zona horaria no es válida.']);
    }

    public function test_provision_accepts_and_persists_an_iana_timezone(): void
    {
        $this->seedPlanMatrix();

        $response = $this->withToken($this->superAdminToken())
            ->postJson('/api/tenant/provision', $this->provisionBody(['timezone' => 'America/Tijuana']))
            ->assertStatus(201);

        $this->assertSame('America/Tijuana', Tenant::find($response->json('data.tenant.id'))->timezone);
    }

    public function test_provision_without_timezone_keeps_the_default(): void
    {
        $this->seedPlanMatrix();

        $response = $this->withToken($this->superAdminToken())
            ->postJson('/api/tenant/provision', $this->provisionBody())
            ->assertStatus(201);

        $this->assertSame('America/Mexico_City', Tenant::find($response->json('data.tenant.id'))->timezone);
    }

    public function test_update_rejects_a_non_iana_timezone_and_keeps_the_stored_one(): void
    {
        $tenant = $this->makeTenant();

        $this->withToken($this->superAdminToken())
            ->putJson("/api/tenant/{$tenant->id}", ['timezone' => 'Hora del centro'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone' => 'La zona horaria no es válida.']);

        $this->assertSame('America/Mexico_City', $tenant->fresh()->timezone);
    }

    public function test_update_changes_the_timezone(): void
    {
        $tenant = $this->makeTenant();

        $this->withToken($this->superAdminToken())
            ->putJson("/api/tenant/{$tenant->id}", ['timezone' => 'America/Cancun'])
            ->assertOk();

        $this->assertSame('America/Cancun', $tenant->fresh()->timezone);
    }

    public function test_update_of_other_fields_still_works_without_timezone(): void
    {
        $tenant = $this->makeTenant();

        $this->withToken($this->superAdminToken())
            ->putJson("/api/tenant/{$tenant->id}", ['name' => 'Centro renombrado'])
            ->assertOk();

        $this->assertSame('Centro renombrado', $tenant->fresh()->name);
        $this->assertSame('America/Mexico_City', $tenant->fresh()->timezone);
    }

    private function seedPlanMatrix(): void
    {
        $plan = $this->makePlan('basico', ['suggested_unit_min' => 1, 'suggested_unit_max' => 25]);
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, 'month', 45000);
        $this->makePlanModulePrice($plan, $base, 'year', (int) round(45000 * 12 * 0.95));
    }

    private function superAdminToken(): string
    {
        $user = User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Super '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        return $user->createToken('super', ['*'])->plainTextToken;
    }
}
