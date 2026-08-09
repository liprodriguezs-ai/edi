<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\SubscriptionPlan\Models\SubscriptionPayment;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Data-layer coverage for the billing v2 per-module schema: the tenant billing
 * columns, the billable_module / plan_module_price matrix, the per-module
 * tenant_entitlement, and the subscription_payment ledger (now keyed by module +
 * plan).
 *
 * Central-connection writes (no tenant scope), so transaction wrapping is disabled
 * and the touched central tables are reset in tearDown.
 */
final class SubscriptionPaymentDataTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->cleanBillingTables();

        parent::tearDown();
    }

    // =========================================================================
    // Migrations ran
    // =========================================================================

    public function test_migrations_added_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('tenant', 'payment_method'));
        $this->assertTrue(Schema::hasColumn('tenant', 'billing_package_id'));

        $this->assertTrue(Schema::hasTable('billable_module'));
        $this->assertTrue(Schema::hasTable('plan_module_price'));
        $this->assertTrue(Schema::hasColumn('tenant_entitlement', 'billable_module_id'));
        $this->assertTrue(Schema::hasColumn('tenant_entitlement', 'billing_package_id'));

        $this->assertTrue(Schema::hasTable('subscription_payment'));
        $this->assertTrue(Schema::hasColumns('subscription_payment', [
            'tenant_id',
            'billable_module_id',
            'billing_package_id',
            'tenant_entitlement_id',
            'amount',
            'currency',
            'method',
            'paid_at',
            'period_start',
            'period_end',
            'recorded_by_user_id',
            'stripe_invoice_id',
            'notes',
        ]));
    }

    // =========================================================================
    // Defaults
    // =========================================================================

    public function test_tenant_payment_method_defaults_to_card(): void
    {
        $tenant = $this->makeTenant();

        $this->assertSame(Tenant::PAYMENT_METHOD_CARD, $tenant->fresh()->payment_method);
    }

    // =========================================================================
    // Model: casts, fillable, relations
    // =========================================================================

    public function test_subscription_payment_casts_and_relations(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan('esencial');
        $module = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);

        $entitlement = TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->addMonth(),
        ]);

        $payment = SubscriptionPayment::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $plan->id,
            'tenant_entitlement_id' => $entitlement->id,
            'amount' => 95000,
            'currency' => 'MXN',
            'method' => SubscriptionPayment::METHOD_TRANSFER,
            'paid_at' => now(),
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'notes' => 'Pago por transferencia ref 12345',
        ]);

        $fresh = $payment->fresh();

        // Casts
        $this->assertIsInt($fresh->amount);
        $this->assertSame(95000, $fresh->amount);
        $this->assertInstanceOf(Carbon::class, $fresh->paid_at);
        $this->assertInstanceOf(Carbon::class, $fresh->period_start);
        $this->assertInstanceOf(Carbon::class, $fresh->period_end);

        // Relations
        $this->assertSame($tenant->id, $fresh->tenant->id);
        $this->assertSame($module->id, $fresh->billable_module->id);
        $this->assertSame($plan->id, $fresh->billing_package->id);
        $this->assertSame($entitlement->id, $fresh->tenant_entitlement->id);

        // Method constants
        $this->assertContains($fresh->method, SubscriptionPayment::METHODS);
    }
}
