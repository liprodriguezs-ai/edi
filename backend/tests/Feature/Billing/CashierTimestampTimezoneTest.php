<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Http\Controllers\StripeWebhookController;
use App\Models\Role as AppRole;
use App\Models\Subscription as CashierSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Billing\Concerns\BillingMatrixFixtures;
use Tests\TestCase;

/**
 * cashier-timestamp-timezone (Phase 9) — proves the AppTimezoneCast fix on the
 * REAL write path: Cashier's OWN `WebhookController::handleCustomerSubscriptionUpdated`
 * (not the app's `StripeWebhookListener`, which never touches these columns),
 * invoked directly (no HTTP/signature layer — `handleWebhook()` is called with a
 * hand-built Request, mirroring the sibling `dispatchWebhook()` test helpers that
 * already bypass routing for the same reason).
 *
 * App timezone is forced to a non-UTC zone for the whole class so the skew this
 * fix corrects is actually exercised, regardless of the environment's own
 * APP_TIMEZONE (.env.testing uses UTC, where the bug would be a silent no-op).
 */
final class CashierTimestampTimezoneTest extends TestCase
{
    use BillingMatrixFixtures;
    use RefreshDatabase;

    private const PRICE_ID = 'price_tz_test';

    private string $originalDefaultTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Mexico_City');
        Config::set('app.timezone', 'America/Mexico_City');
    }

    protected function tearDown(): void
    {
        DB::table('subscriptions')->delete();
        date_default_timezone_set($this->originalDefaultTimezone);
        Carbon::setTestNow();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_webhook_written_trial_ends_at_agrees_with_eloquent_written_created_at(): void
    {
        $now = Carbon::now();

        $tenant = $this->makeTenant();
        $owner = $this->makeStripeOwner($tenant, 'cus_tz_write');

        // Stripe's Unix timestamp for "30 days from now" — timezone-agnostic by
        // construction (a Unix timestamp has no timezone of its own).
        $trialEnd = $now->copy()->addDays(30);

        $this->dispatchRealWebhook('customer.subscription.updated', [
            'id' => 'sub_tz_write',
            'customer' => 'cus_tz_write',
            'status' => 'trialing',
            'trial_end' => $trialEnd->getTimestamp(),
            'items' => ['data' => [$this->itemPayload('si_tz_write')]],
        ]);

        $subscription = CashierSubscription::where('stripe_id', 'sub_tz_write')->firstOrFail();

        // Eloquent's OWN created_at write (this codebase's ground truth for
        // "correct local wall time") must land at "now", the same instant used
        // to build the fixture — no code path here can introduce a skew.
        $this->assertSame($now->format('Y-m-d H:i'), $subscription->created_at->format('Y-m-d H:i'));

        // The Cashier-written, cast-normalized trial_ends_at must land EXACTLY
        // 30 days after that same local wall clock — never +6h off from it, the
        // skew this fix eliminates.
        $this->assertSame($trialEnd->format('Y-m-d H:i'), $subscription->trial_ends_at->format('Y-m-d H:i'));
    }

    public function test_ontrial_is_false_the_instant_the_stored_trial_expiry_passes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 09:00:00'));

        $tenant = $this->makeTenant();
        $owner = $this->makeStripeOwner($tenant, 'cus_tz_ontrial');

        $trialEnd = Carbon::now()->addMinute();

        $this->dispatchRealWebhook('customer.subscription.updated', [
            'id' => 'sub_tz_ontrial',
            'customer' => 'cus_tz_ontrial',
            'status' => 'trialing',
            'trial_end' => $trialEnd->getTimestamp(),
            'items' => ['data' => [$this->itemPayload('si_tz_ontrial')]],
        ]);

        $subscription = CashierSubscription::where('stripe_id', 'sub_tz_ontrial')->firstOrFail();
        $this->assertTrue($subscription->onTrial());

        // A surviving +6h skew would keep onTrial() true here — the ghost-trial
        // window this fix eliminates.
        Carbon::setTestNow($trialEnd->copy()->addSecond());

        $this->assertFalse($subscription->fresh()->onTrial());
    }

    public function test_cancelling_a_non_trialing_subscription_stores_stripes_cancel_at(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeStripeOwner($tenant, 'cus_tz_cancel');

        $this->dispatchRealWebhook('customer.subscription.updated', [
            'id' => 'sub_tz_cancel',
            'customer' => 'cus_tz_cancel',
            'status' => 'active',
            'items' => ['data' => [$this->itemPayload('si_tz_cancel')]],
        ]);

        $subscription = CashierSubscription::where('stripe_id', 'sub_tz_cancel')->firstOrFail();
        $this->assertFalse($subscription->onTrial(), 'fixture subscription must not be trialing');

        $cancelAt = Carbon::now()->addDays(23); // the paid period end, per Stripe's cancel_at

        $this->dispatchRealWebhook('customer.subscription.updated', [
            'id' => 'sub_tz_cancel',
            'customer' => 'cus_tz_cancel',
            'status' => 'active',
            'cancel_at' => $cancelAt->getTimestamp(),
            'items' => ['data' => [$this->itemPayload('si_tz_cancel')]],
        ]);

        $subscription->refresh();

        $this->assertNotNull($subscription->ends_at);
        $this->assertSame($cancelAt->format('Y-m-d H:i'), $subscription->ends_at->format('Y-m-d H:i'));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(string $itemId): array
    {
        return [
            'id' => $itemId,
            'quantity' => 1,
            'price' => [
                'id' => self::PRICE_ID,
                'product' => 'prod_tz_test',
                'recurring' => ['interval' => 'month'],
            ],
        ];
    }

    /**
     * Invoke Cashier's REAL `WebhookController::handleWebhook()` directly (no
     * HTTP/signature layer, mirroring the sibling `dispatchWebhook()` helpers in
     * this suite) so the actual `createFromTimestamp()` write path under test
     * runs, not just the app's own StripeWebhookListener.
     *
     * @param  array<string, mixed>  $objectData
     */
    private function dispatchRealWebhook(string $type, array $objectData): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $payload = [
            'id' => 'evt_'.Str::lower(Str::random(16)),
            'type' => $type,
            'data' => ['object' => $objectData],
        ];

        $request = Request::create('/api/stripe/webhook', 'POST', content: json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');

        app(StripeWebhookController::class)->handleWebhook($request);
    }

    /**
     * The organization's account owner, carrying the Stripe customer id (precedent:
     * StripeWebhookEntitlementTest::makeStripeOwner()).
     */
    private function makeStripeOwner(Tenant $tenant, string $stripeCustomerId): User
    {
        $user = User::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Owner '.Str::random(5),
            'email' => 'owner.'.Str::lower(Str::random(10)).'@example.test',
            'auth_active' => true,
        ]);

        $user->forceFill(['stripe_id' => $stripeCustomerId])->save();

        AppRole::firstOrCreate(['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']);

        $user->tenants()->attach($tenant->id);
        $user->assignRole(Role::SLUG_ACCOUNT_OWNER);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
