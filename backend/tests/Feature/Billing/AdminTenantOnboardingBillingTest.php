<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\SubscriptionPlan\Models\PlanModulePrice;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Onboarding flow #2 — super-admin ALTA of a brand-new organization + its billing:
 *
 *   POST /api/tenant                      (Orion default store, no billing logic)
 *     -> GET /api/tenant/{id}/billing     (initial aggregate must be coherent)
 *       -> POST .../subscription-payment  (manual paid module for the new organization)
 *
 * Billing v2: the billing aggregate is PER-MODULE and the manual payment lines
 * carry {module_key, plan_key, amount, interval}. Network-free, central-connection
 * writes only, transaction-less with a central-table reset in tearDown.
 */
final class AdminTenantOnboardingBillingTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

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

    // =========================================================================
    // ALTA + initial billing aggregate
    // =========================================================================

    public function test_admin_creates_tenant_and_billing_reports_coherent_empty_state(): void
    {
        $superToken = $this->superAdminToken();

        // tenants:seed seeds ZERO billing rows; suppress it for speed/isolation.
        Event::fake([TenantCreated::class]);

        $create = $this->authed($superToken)->postJson('/api/tenant', [
            'name' => 'Nuevo Organization '.Str::random(5),
            'status' => Tenant::STATUS_ACTIVO,
        ]);

        $create->assertStatus(201);
        $tenantId = $create->json('data.id');
        $this->assertNotEmpty($tenantId);

        // A just-created organization: payment_method defaults to card, born ACTIVO, and
        // carries NO billing entitlements at all (none seeded on alta).
        $tenant = Tenant::find($tenantId);
        $this->assertSame(Tenant::PAYMENT_METHOD_CARD, $tenant->payment_method);
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->status);
        $this->assertSame(0, TenantEntitlement::where('tenant_id', $tenantId)->count());

        // Seed a plan + base module so available_modules is non-empty (catalog exists),
        // but the organization still has no entitlements.
        $this->seedPlanMatrix();

        $billing = $this->authed($superToken)
            ->getJson("/api/tenant/{$tenantId}/billing")
            ->assertStatus(200);

        $billing->assertJsonPath('data.payment_method', Tenant::PAYMENT_METHOD_CARD)
            ->assertJsonPath('data.status', Tenant::STATUS_ACTIVO)
            ->assertJsonPath('data.entitlements', [])
            ->assertJsonPath('data.ledger', [])
            ->assertJsonPath('data.ledger_total', 0);

        // GAP (chicken-and-egg the UI hits): no entitlements → empty suggested
        // manual-payment form, even though the catalog has assignable modules.
        $this->assertSame([], $billing->json('data.manual_payment.lines'));
        $this->assertSame(0, $billing->json('data.manual_payment.total'));
        $this->assertNotEmpty(
            $billing->json('data.available_modules'),
            'the assignable-module catalog is available even when lines is empty',
        );
    }

    // =========================================================================
    // manual PAID plan for a fresh organization: refused on card, works off card
    // =========================================================================

    public function test_admin_manual_paid_plan_for_fresh_tenant_needs_method_switch_then_succeeds(): void
    {
        $superToken = $this->superAdminToken();
        [$plan, $base] = $this->seedPlanMatrix();

        Event::fake([TenantCreated::class]);
        $tenantId = $this->authed($superToken)
            ->postJson('/api/tenant', ['name' => 'Organization Manual '.Str::random(5), 'status' => Tenant::STATUS_ACTIVO])
            ->assertStatus(201)
            ->json('data.id');

        $manualBody = [
            'method' => 'transfer',
            'lines' => [
                ['module_key' => 'base', 'plan_key' => $plan->key, 'amount' => 95000, 'interval' => PlanModulePrice::INTERVAL_MONTH],
            ],
        ];

        // (A) On a card organization the manual payment is REFUSED (card is Stripe's
        // domain; a manual grant would clobber a Stripe entitlement). 422.
        $refused = $this->authed($superToken)
            ->postJson("/api/tenant/{$tenantId}/subscription-payment", $manualBody)
            ->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('tarjeta', (string) $refused->json('message'));

        // (B) The admin first switches the organization OFF card.
        $this->authed($superToken)
            ->patchJson("/api/tenant/{$tenantId}/payment-method", ['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER])
            ->assertStatus(200)
            ->assertJsonPath('data.payment_method', Tenant::PAYMENT_METHOD_TRANSFER);

        // (C) Now the SAME request bootstraps a PAID plan onto the fresh organization:
        // a source=MANUAL entitlement is created for the module it never "contracted".
        $recorded = $this->authed($superToken)
            ->postJson("/api/tenant/{$tenantId}/subscription-payment", $manualBody)
            ->assertStatus(201);
        $this->assertCount(1, $recorded->json('data'));

        $entitlement = TenantEntitlement::query()
            ->where('tenant_id', $tenantId)
            ->where('billable_module_id', $base->id)
            ->first();

        $this->assertNotNull($entitlement, 'a fresh organization CAN be granted a paid manual entitlement');
        $this->assertSame(TenantEntitlement::SOURCE_MANUAL, $entitlement->source);
        $this->assertSame($plan->id, $entitlement->billing_package_id);
        $this->assertSame(PlanModulePrice::INTERVAL_MONTH, $entitlement->interval);
        $this->assertEqualsWithDelta(now()->addMonth()->timestamp, $entitlement->expires_at->timestamp, 5);

        // The ledger now carries the bootstrap payment.
        $ledger = $this->authed($superToken)
            ->getJson("/api/tenant/{$tenantId}/billing")
            ->assertStatus(200);
        $this->assertSame(95000, $ledger->json('data.ledger_total'));
        $this->assertCount(1, $ledger->json('data.manual_payment.lines'));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @return array{0: BillingPackage, 1: BillableModule}
     */
    private function seedPlanMatrix(): array
    {
        $plan = $this->makePlan('esencial');
        $base = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $this->makePlanModulePrice($plan, $base, 'month', 95000);
        $this->makePlanModulePrice($plan, $base, 'year', (int) round(95000 * 12 * 0.95));

        return [$plan, $base];
    }

    private function superAdminToken(): string
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => 'Super '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        return $user->createToken('super', ['*'])->plainTextToken;
    }

    private function authed(string $token): static
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->withToken($token);

        return $this;
    }
}
