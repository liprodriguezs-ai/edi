<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Read aggregate that feeds the super-admin organization billing screen:
 *   - GET tenant/{id}/billing
 *
 * Billing v2: per-module entitlements, each carrying the PLAN it was granted under.
 * Network-free; central-connection writes reset in tearDown.
 */
final class TenantBillingReadEndpointTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->cleanBillingTables();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_it_returns_the_billing_aggregate_with_the_expected_shape(): void
    {
        $super = $this->makeUser();

        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $package = $this->makeBillableModule('package', 'Paquetería', [], sortOrder: 1);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_MONTH, 95000);
        $this->makePlanModulePrice($plan, $base, PlanModulePrice::INTERVAL_YEAR, 1083000);
        $this->makePlanModulePrice($plan, $package, PlanModulePrice::INTERVAL_MONTH, 12000);

        $tenant = $this->makeTenant([
            'payment_method' => Tenant::PAYMENT_METHOD_TRANSFER,
            'billing_package_id' => $plan->id,
        ]);

        // PAID (source=manual) base entitlement → priced from the plan (95000).
        $entitlement = TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_MANUAL,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => now()->addMonth(),
        ]);

        // COURTESY (super_admin_free) module → $0, excluded from the manual payment.
        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $package->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => PlanModulePrice::INTERVAL_MONTH,
            'quantity' => 1,
            'expires_at' => null,
        ]);

        SubscriptionPayment::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $base->id,
            'billing_package_id' => $plan->id,
            'tenant_entitlement_id' => $entitlement->id,
            'amount' => 95000,
            'currency' => 'MXN',
            'method' => SubscriptionPayment::METHOD_TRANSFER,
            'paid_at' => now(),
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'recorded_by_user_id' => $super->id,
            'notes' => 'Pago inicial',
        ]);

        $response = $this->request($this->superAdminToken($super))
            ->getJson("/api/tenant/{$tenant->id}/billing")
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'payment_method',
                    'status',
                    'plan',
                    'entitlements' => [['module_key', 'module_name', 'plan_key', 'plan_name', 'source', 'interval', 'amount', 'expires_at']],
                    'available_modules' => [['key', 'name', 'is_base']],
                    'available_plans',
                    'manual_payment' => ['lines' => [['module_key', 'name', 'plan_key', 'interval', 'amount']], 'total'],
                    'ledger' => [['module_key', 'plan_key', 'amount', 'currency', 'method', 'paid_at', 'period_end', 'notes']],
                    'ledger_total',
                ],
            ])
            ->assertJsonPath('data.payment_method', Tenant::PAYMENT_METHOD_TRANSFER)
            ->assertJsonPath('data.status', Tenant::STATUS_ACTIVO)
            ->assertJsonPath('data.plan.key', 'esencial')
            ->assertJsonPath('data.ledger_total', 95000)
            ->assertJsonPath('data.ledger.0.amount', 95000)
            ->assertJsonPath('data.ledger.0.module_key', 'base')
            ->assertJsonPath('data.ledger.0.method', SubscriptionPayment::METHOD_TRANSFER);

        // Price PER MODULE derived from the plan.
        $entitlements = collect($response->json('data.entitlements'));

        $baseRow = $entitlements->firstWhere('module_key', 'base');
        $this->assertSame(TenantEntitlement::SOURCE_MANUAL, $baseRow['source']);
        $this->assertSame('esencial', $baseRow['plan_key']);
        $this->assertSame(PlanModulePrice::INTERVAL_MONTH, $baseRow['interval']);
        $this->assertSame(95000, $baseRow['amount']);

        // The per-module price equals what PlanPricingService quotes for that cell.
        $expectedBasePrice = app(PlanPricingService::class)->priceForModule($plan, $base, PlanModulePrice::INTERVAL_MONTH);
        $this->assertSame($expectedBasePrice, $baseRow['amount']);

        // Courtesy module is $0.
        $courtesyRow = $entitlements->firstWhere('module_key', 'package');
        $this->assertSame(TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, $courtesyRow['source']);
        $this->assertSame(0, $courtesyRow['amount']);

        // Suggested manual payment covers only the PAID module; courtesy excluded.
        $manual = $response->json('data.manual_payment');
        $this->assertCount(1, $manual['lines']);
        $this->assertSame('base', $manual['lines'][0]['module_key']);
        $this->assertSame(95000, $manual['lines'][0]['amount']);
        $this->assertSame(95000, $manual['total']);

        // Assignable modules include base + package.
        $modules = collect($response->json('data.available_modules'));
        $this->assertEqualsCanonicalizing(['base', 'package'], $modules->pluck('key')->all());
        $this->assertTrue($modules->firstWhere('key', 'base')['is_base']);

        // The plan matrix is exposed for assignment.
        $plans = collect($response->json('data.available_plans'));
        $this->assertTrue($plans->contains(fn (array $p): bool => $p['key'] === 'esencial'));
    }

    public function test_it_is_forbidden_for_a_non_super_admin(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser();

        $token = $user->createToken('limited', ['read.tenant'])->plainTextToken;

        $this->request($token)
            ->getJson("/api/tenant/{$tenant->id}/billing")
            ->assertStatus(403);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUser(): User
    {
        return User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Super '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);
    }

    private function superAdminToken(User $user): string
    {
        return $user->createToken('super', ['*'])->plainTextToken;
    }

    private function request(string $token): self
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $this->withToken($token);
    }
}
