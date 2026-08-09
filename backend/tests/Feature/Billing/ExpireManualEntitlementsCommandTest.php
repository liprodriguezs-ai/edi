<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\System\Models\System;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Coverage for the billing:expire-manual-entitlements daily cron (per-module).
 *
 * The cron blocks a cluster once its non-stripe entitlement (trial / manual /
 * super_admin_free) lapses AND no coverage of any source remains, and it ALWAYS
 * recomputes access so a lapsed module's module_tenant.enabled flips off — even
 * when another entitlement keeps the cluster up (partial lapse). It NEVER treats a
 * stripe row as a trigger (webhook territory). Expiration is driven with travelTo()
 * so a future-dated, active entitlement is the same row that later trips the cron.
 *
 * Central-connection writes (no tenant scope), so transaction wrapping is disabled
 * and the touched central tables are reset in tearDown.
 */
final class ExpireManualEntitlementsCommandTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->travelBack();
        $this->cleanBillingTables();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_blocks_a_manual_tenant_whose_entitlement_expired_and_disables_modules(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $module = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, [
            'interval' => 'month',
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertTrue($this->moduleEnabled($tenant, $moduleRow));
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);

        $this->travelTo(now()->addDays(31));

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_BLOQUEADO, $tenant->fresh()->status);
        $this->assertFalse($this->moduleEnabled($tenant, $moduleRow));
        $this->assertSame(0, $tenant->systems()->wherePivot('is_addon', false)->count());
    }

    public function test_blocks_a_manual_tenant_whose_manual_source_entitlement_expired_and_disables_modules(): void
    {
        // A source=MANUAL renewal must lapse and block exactly like super_admin_free.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CASH]);

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $module = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_MANUAL, [
            'interval' => 'month',
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertTrue($this->moduleEnabled($tenant, $moduleRow));
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);

        $this->travelTo(now()->addDays(31));

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_BLOQUEADO, $tenant->fresh()->status);
        $this->assertFalse($this->moduleEnabled($tenant, $moduleRow));
        $this->assertSame(0, $tenant->systems()->wherePivot('is_addon', false)->count());
    }

    public function test_does_not_block_when_manual_entitlement_lapsed_but_another_source_is_active(): void
    {
        // A manual entitlement lapsing must NOT block the cluster while ANY other
        // source (here stripe, on a different module) remains active.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);

        $plan = $this->makePlan('esencial');
        $lapsedModule = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $activeModule = $this->makeBillableModule('package', 'Paquetería');

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $lapsedModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_MANUAL,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->subDay(),
        ]);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $activeModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_manual_mix',
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
    }

    public function test_partial_lapse_disables_only_the_expired_addons_module_and_keeps_the_base(): void
    {
        // Base (super_admin_free, perpetual) stays; an addon TRIAL lapses → the addon
        // module flips OFF, the base stays ON, and the cluster is NOT blocked.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);

        $system = System::factory()->create();
        $baseModuleRow = $this->makeModuleIn($system, 'Cobros');
        $addonModuleRow = $this->makeModuleIn($system, 'Paquetería');

        $baseModule = $this->makeBillableModule('base', 'Plan Base', [$baseModuleRow], isBase: true);
        $addonModule = $this->makeBillableModule('package', 'Paquetería', [$addonModuleRow]);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $baseModule, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, [
            'interval' => 'month',
            'expires_at' => null,
        ]);

        $this->grantModule($tenant, $addonModule, $plan, TenantEntitlement::SOURCE_TRIAL, [
            'interval' => 'month',
            'expires_at' => now()->addDays(14),
        ]);

        $this->assertTrue($this->moduleEnabled($tenant, $baseModuleRow));
        $this->assertTrue($this->moduleEnabled($tenant, $addonModuleRow));
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);

        $this->travelTo(now()->addDays(15));

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertFalse($this->moduleEnabled($tenant, $addonModuleRow), 'expired addon module must be disabled');
        $this->assertTrue($this->moduleEnabled($tenant, $baseModuleRow), 'active base module must stay enabled');
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status, 'cluster must stay active while base coverage remains');
    }

    public function test_does_not_touch_a_card_tenant_with_an_active_stripe_entitlement(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $module = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_STRIPE, [
            'interval' => 'month',
            'stripe_subscription_id' => 'sub_card_active',
            'expires_at' => now()->addDays(30),
        ]);

        $this->travelTo(now()->addDays(10));

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
        $this->assertTrue($this->moduleEnabled($tenant, $moduleRow));
    }

    public function test_blocks_a_card_tenant_whose_trial_expired_and_disables_modules(): void
    {
        // A card cluster that took the onboarding trial (source=trial) and never
        // subscribed to Stripe has NO webhook — the cron must block + recompute it.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $module = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_TRIAL, [
            'interval' => 'month',
            'expires_at' => now()->addDays(14),
        ]);

        $this->assertTrue($this->moduleEnabled($tenant, $moduleRow));
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);

        $this->travelTo(now()->addDays(15));

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_BLOQUEADO, $tenant->fresh()->status);
        $this->assertFalse($this->moduleEnabled($tenant, $moduleRow));
        $this->assertSame(0, $tenant->systems()->wherePivot('is_addon', false)->count());
    }

    public function test_does_not_block_a_manual_tenant_with_an_active_entitlement(): void
    {
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CASH]);

        $module = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_SUPER_ADMIN_FREE, [
            'interval' => 'month',
            'expires_at' => now()->addDays(30),
        ]);

        $this->travelTo(now()->addDays(10));

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
    }

    public function test_does_not_block_when_one_manual_entitlement_lapsed_but_another_is_active(): void
    {
        // A tenant can hold super_admin_free entitlements on several modules; one
        // lapsing must NOT block while another remains active.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);

        $plan = $this->makePlan('esencial');
        $expiredModule = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $activeModule = $this->makeBillableModule('package', 'Paquetería');

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $expiredModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->subDay(),
        ]);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $activeModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
    }

    public function test_reconciles_the_status_of_a_tenant_whose_stripe_entitlement_expired_without_blocking_or_recomputing(): void
    {
        // F-27 — a stripe entitlement can lapse with no `deleted` webhook (dunning
        // ends in `unpaid`, or the event is lost). The API already denies access
        // (computed live off the entitlement), but tenant.status kept reading
        // "activo" forever and every super-admin panel / report lied.
        //
        // The cron reconciles the STATUS ONLY: pendiente_pago (Stripe may still
        // recover), never bloqueado (that is customer.subscription.deleted's call),
        // and never recompute() — which PURGES the cluster's module settings and
        // would destroy real configuration during a recoverable dunning window.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $module = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_STRIPE, [
            'interval' => 'month',
            'stripe_subscription_id' => 'sub_orphan',
            'expires_at' => now()->addDays(30),
        ]);

        $this->travelTo(now()->addDays(31));

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_PENDIENTE_PAGO, $tenant->fresh()->status);
        $this->assertTrue(
            $this->moduleEnabled($tenant, $moduleRow),
            'the projection must be left alone: recompute() purges module settings and Stripe may still recover'
        );
    }

    public function test_does_not_reconcile_a_tenant_whose_stripe_entitlement_is_still_active(): void
    {
        // The sacred guarantee: a card cluster with a LIVE entitlement is never
        // touched by this cron, in any way.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);

        $plan = $this->makePlan('esencial');
        $module = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $module->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->addDays(20),
        ]);

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
    }

    public function test_does_not_reconcile_a_stripe_lapse_covered_by_another_active_grant(): void
    {
        // An expired stripe row next to a live courtesy/manual grant is NOT a
        // payment problem — the cluster is covered, status must stay activo.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);

        $plan = $this->makePlan('esencial');
        $lapsed = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $covering = $this->makeBillableModule('package', 'Paquetería', []);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $lapsed->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->subDay(),
        ]);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $covering->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => null,
        ]);

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
    }

    public function test_does_not_block_when_super_admin_free_lapsed_but_another_source_is_active(): void
    {
        // Block decision aligns with recompute (reads EVERY active row): a lapsed
        // super_admin_free with a still-active stripe entitlement must NOT block.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);

        $plan = $this->makePlan('esencial');
        $lapsedModule = $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
        $activeModule = $this->makeBillableModule('package', 'Paquetería');

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $lapsedModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_SUPER_ADMIN_FREE,
            'interval' => 'month',
            'quantity' => 1,
            'expires_at' => now()->subDay(),
        ]);

        TenantEntitlement::create([
            'tenant_id' => $tenant->id,
            'billable_module_id' => $activeModule->id,
            'billing_package_id' => $plan->id,
            'source' => TenantEntitlement::SOURCE_STRIPE,
            'interval' => 'month',
            'quantity' => 1,
            'stripe_subscription_id' => 'sub_m3',
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
    }

    public function test_notifies_an_expired_trial_only_once_across_daily_runs(): void
    {
        // Expired trial rows are kept by design (never deleted), so the pre-fix cron
        // re-selected the same rows and re-sent "tu prueba de {módulo} venció" every
        // run. The fix stamps trial_expiry_notified_at after processing and filters the
        // notify select on whereNull(...), so a second daily sweep does NOT re-notify.
        // (The notification itself is unobservable here — no admin recipients and the
        // trial_expired event is not seeded — so the marker is the deterministic seam.)
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $module = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');

        $entitlement = $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_TRIAL, [
            'interval' => 'month',
            'expires_at' => now()->addDays(14),
        ]);

        $this->assertNull($entitlement->fresh()->trial_expiry_notified_at);

        // Day 15: trial lapsed. The first sweep processes and marks it.
        $this->travelTo(now()->addDays(15));
        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $firstMark = $entitlement->fresh()->trial_expiry_notified_at;
        $this->assertNotNull($firstMark, 'the cron must stamp trial_expiry_notified_at after processing an expired trial');

        // Day 16: the second sweep must NOT re-process the already-notified trial — the
        // marker stays at the first run's timestamp (a re-fire would re-stamp it).
        $this->travelTo(now()->addDays(1));
        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $secondMark = $entitlement->fresh()->trial_expiry_notified_at;
        $this->assertSame(
            $firstMark->toDateTimeString(),
            $secondMark->toDateTimeString(),
            'a second daily run must not re-notify (re-stamp) an already-notified expired trial',
        );
    }

    public function test_does_not_flag_a_trial_that_was_paid_after_it_lapsed(): void
    {
        // A module trialed and THEN paid must never be reported as an expired trial:
        // grant() upserts the (tenant, billable_module) row IN PLACE, flipping source off
        // 'trial', so the cron's source=trial select no longer matches it. The paying
        // admin is never told their access lapsed, and the row is never stamped or
        // blocked. (With the unique(tenant_id, billable_module_id) constraint this
        // upsert-in-place is the real "since paid" protection — the command's
        // module-still-active guard is defensive belt-and-suspenders on top of it.)
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_TRANSFER]);

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $module = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');

        $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_TRIAL, [
            'interval' => 'month',
            'expires_at' => now()->addDays(14),
        ]);

        // Day 15: the trial has lapsed, but the cluster pays for the module — the SAME
        // row upserts to source=manual with a fresh future expiry.
        $this->travelTo(now()->addDays(15));
        $paid = $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_MANUAL, [
            'interval' => 'month',
            'expires_at' => now()->addDays(30),
        ]);

        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $paid = $paid->fresh();
        $this->assertSame(TenantEntitlement::SOURCE_MANUAL, $paid->source);
        $this->assertNull($paid->trial_expiry_notified_at, 'a paid module must not be flagged as an expired trial');
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);
        $this->assertTrue($this->moduleEnabled($tenant, $moduleRow));
    }

    public function test_marks_and_blocks_a_freshly_lapsed_trial_in_a_single_run(): void
    {
        // Existing behavior intact: a freshly lapsed trial with no other coverage is
        // marked once, the cluster is blocked, and its modules are disabled — proving
        // the notify-loop rewrite did not break the block / recompute decision.
        $tenant = $this->makeTenant(['payment_method' => Tenant::PAYMENT_METHOD_CARD]);

        $system = System::factory()->create();
        $moduleRow = $this->makeModuleIn($system, 'Cobros');
        $module = $this->makeBillableModule('base', 'Plan Base', [$moduleRow], isBase: true);
        $plan = $this->makePlan('esencial');

        $entitlement = $this->grantModule($tenant, $module, $plan, TenantEntitlement::SOURCE_TRIAL, [
            'interval' => 'month',
            'expires_at' => now()->addDays(14),
        ]);

        $this->assertTrue($this->moduleEnabled($tenant, $moduleRow));
        $this->assertSame(Tenant::STATUS_ACTIVO, $tenant->fresh()->status);

        $this->travelTo(now()->addDays(15));
        $this->artisan('billing:expire-manual-entitlements')->assertExitCode(0);

        $this->assertNotNull($entitlement->fresh()->trial_expiry_notified_at, 'a freshly lapsed trial must be marked as notified');
        $this->assertSame(Tenant::STATUS_BLOQUEADO, $tenant->fresh()->status);
        $this->assertFalse($this->moduleEnabled($tenant, $moduleRow));
    }
}
