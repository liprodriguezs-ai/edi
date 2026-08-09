<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SubscriptionPlan\Models\BillableModule;
use Modules\SubscriptionPlan\Services\PlanPricingService;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * Plan 052 — gate the public plan-quoter to public, active plans only.
 *
 * POST /api/billing/plan-quote is public/unauthenticated (throttled only) and
 * feeds the landing pricing page. quoteByPlan() does NOT gate is_public/is_active
 * by default (documented on PlanPricingService, S2) because AUTHENTICATED,
 * super-admin-gated callers (checkout, preferential swap) legitimately quote
 * hidden/negotiated or not-yet-activated plans. Only the public controller opts
 * into the gate via quoteByPlan(..., publicOnly: true).
 *
 * These tests prove:
 *  - a public+active plan still quotes normally through the public endpoint;
 *  - a hidden (is_public=false) plan 422s with the SAME "Plan inválido" message
 *    template as an unknown key — no existence oracle, no leaked pricing;
 *  - a retired (is_active=false) public plan 422s the same way;
 *  - the default (publicOnly=false) path used by every OTHER caller is
 *    UNCHANGED — it still resolves hidden/inactive plans (guards
 *    PreferentialSwapService/MultiItemSubscriptionService without needing the
 *    full authenticated flow here).
 */
final class BillingPlanQuoteVisibilityTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    private const UNIT_AMOUNT = 50000;

    private function seedBaseModule(): BillableModule
    {
        return $this->makeBillableModule('base', 'Plan Base', [], isBase: true);
    }

    public function test_public_active_plan_quotes_normally_through_public_endpoint(): void
    {
        $base = $this->seedBaseModule();
        $plan = $this->makePlan('plan-public-active');
        $this->makePlanModulePrice($plan, $base, 'month', self::UNIT_AMOUNT);

        $response = $this->postJson('/api/billing/plan-quote', [
            'plan_key' => $plan->key,
            'modules' => ['base'],
            'interval' => 'month',
        ]);

        $response->assertOk()
            ->assertJsonPath('plan.key', $plan->key)
            ->assertJsonPath('total', self::UNIT_AMOUNT);
    }

    public function test_hidden_plan_is_rejected_with_generic_unknown_plan_message(): void
    {
        $base = $this->seedBaseModule();
        $hiddenPlan = $this->makePlan('plan-hidden', ['is_public' => false]);
        $this->makePlanModulePrice($hiddenPlan, $base, 'month', self::UNIT_AMOUNT);

        $response = $this->postJson('/api/billing/plan-quote', [
            'plan_key' => $hiddenPlan->key,
            'modules' => ['base'],
            'interval' => 'month',
        ]);

        // 422 with ONLY the generic message — no plan name, no pricing leaked.
        $response->assertStatus(422)
            ->assertExactJson(['message' => "Plan inválido: {$hiddenPlan->key}."]);
    }

    public function test_inactive_public_plan_is_rejected_with_generic_unknown_plan_message(): void
    {
        $base = $this->seedBaseModule();
        $inactivePlan = $this->makePlan('plan-retired', ['is_active' => false]);
        $this->makePlanModulePrice($inactivePlan, $base, 'month', self::UNIT_AMOUNT);

        $response = $this->postJson('/api/billing/plan-quote', [
            'plan_key' => $inactivePlan->key,
            'modules' => ['base'],
            'interval' => 'month',
        ]);

        $response->assertStatus(422)
            ->assertExactJson(['message' => "Plan inválido: {$inactivePlan->key}."]);
    }

    public function test_hidden_plan_error_matches_truly_unknown_key_format(): void
    {
        $base = $this->seedBaseModule();
        $hiddenPlan = $this->makePlan('plan-hidden-2', ['is_public' => false]);
        $this->makePlanModulePrice($hiddenPlan, $base, 'month', self::UNIT_AMOUNT);

        $hiddenResponse = $this->postJson('/api/billing/plan-quote', [
            'plan_key' => $hiddenPlan->key,
            'modules' => ['base'],
            'interval' => 'month',
        ]);

        $unknownResponse = $this->postJson('/api/billing/plan-quote', [
            'plan_key' => 'does-not-exist-at-all',
            'modules' => ['base'],
            'interval' => 'month',
        ]);

        // Same "Plan inválido: {key}." template for both — a hidden plan never
        // produces a message distinguishable from a truly unknown key.
        $hiddenResponse->assertStatus(422)
            ->assertExactJson(['message' => "Plan inválido: {$hiddenPlan->key}."]);
        $unknownResponse->assertStatus(422)
            ->assertExactJson(['message' => 'Plan inválido: does-not-exist-at-all.']);
    }

    public function test_default_publiconly_false_still_resolves_hidden_and_inactive_plans(): void
    {
        // Guards the OTHER callers of quoteByPlan (checkout, preferential swap):
        // with publicOnly defaulting to false they must keep quoting hidden and
        // inactive plans. Asserted directly at the service level; the end-to-end
        // authenticated flow is covered by the PreferentialSwap regression suite.
        $base = $this->seedBaseModule();
        $hiddenPlan = $this->makePlan('plan-hidden-3', ['is_public' => false]);
        $inactivePlan = $this->makePlan('plan-retired-2', ['is_active' => false]);
        $this->makePlanModulePrice($hiddenPlan, $base, 'month', self::UNIT_AMOUNT);
        $this->makePlanModulePrice($inactivePlan, $base, 'month', self::UNIT_AMOUNT);

        $service = app(PlanPricingService::class);

        $hiddenQuote = $service->quoteByPlan($hiddenPlan->key, ['base'], 'month');
        $inactiveQuote = $service->quoteByPlan($inactivePlan->key, ['base'], 'month');

        $this->assertSame(self::UNIT_AMOUNT, $hiddenQuote['total']);
        $this->assertSame(self::UNIT_AMOUNT, $inactiveQuote['total']);
    }
}
