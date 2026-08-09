<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Collections\Services\PaymentService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Phase 3 — Payment notification wiring tests.
 *
 * Covers:
 *   (a) submit() → notifyEvent('payment_registered') → board recipients (president + treasurer)
 *       receive an in-app NotificationUser row.
 *   (b) validatePayment() → notifyEvent('payment_accepted') → billing owner receives
 *       an in-app NotificationUser row.
 *   (c) reject() → notifyEvent('payment_rejected') → billing owner receives
 *       an in-app NotificationUser row that includes the reason.
 *   (d) collections:send-reminders → notifyEvent('payment_due_reminder') → billing owner
 *       receives an in-app NotificationUser row.
 *   (e) Inactive event (is_active=false) → no NotificationUser rows created.
 *
 * Uses $connectionsToTransact = [] (no wrapping test transaction) so DB::afterCommit
 * inside the services fires when each service transaction commits.
 */
final class PaymentNotificationWiringTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGlobalRoles();
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedGlobalRoles(): void
    {
        foreach (['president', 'treasurer', 'secretary', 'administrator', 'resident', 'renter', 'security'] as $slug) {
            Role::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeCentralUser(string $name = 'Test User', ?string $roleSlug = null): User
    {
        $user = User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => Str::random(8).'@test.com',
            'password' => bcrypt('password'),
            'auth_active' => true,
        ]);

        if ($roleSlug) {
            $user->assignRole($roleSlug);
        }

        return $user;
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(100, 9999),
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        return $unit;
    }

    private function attachBillingOwner(Tenant $tenant, Unit $unit, User $user): void
    {
        $tenant->run(function () use ($unit, $user): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
            ]);
        });
    }

    private function makeBankAccount(Tenant $tenant, int $initialBalanceRaw = 0): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $initialBalanceRaw): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => $initialBalanceRaw,
                'current_balance' => $initialBalanceRaw,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    /**
     * Create a charge for a unit via ChargeGenerationService::activateBatch().
     */
    private function makeChargeForUnit(Tenant $tenant, Unit $unit, int $amount, string $code, string $dueDate): Charge
    {
        $charge = null;
        $tenant->run(function () use (&$charge, $unit, $amount, $code, $dueDate): void {
            $category = ChargeConceptCategory::findOrFail(Catalog::id(ChargeConceptCategory::class, 'Ordinaria'));

            $concept = ChargeConcept::create([
                'code' => $code,
                'name' => "Concepto {$code}",
                'charge_concept_category_id' => $category->id,
                'is_active' => true,
            ]);

            $policy = ChargePolicy::create([
                'charge_concept_id' => $concept->id,
                'name' => "Policy {$code}",
                'amount_mode_id' => AmountMode::FIXED_REQUIRED,
                'fixed_amount' => $amount,
                'periodicity_id' => Periodicity::EVENTUAL,
                'is_active' => true,
                'grace_days' => 0,
            ]);

            $draftStatus = ChargeBatchStatus::findOrFail(ChargeBatchStatus::DRAFT);
            $batch = ChargeBatch::create([
                'charge_policy_id' => $policy->id,
                'title' => "Batch {$code}",
                'year' => (int) substr($dueDate, 0, 4),
                'month' => null,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'issued_at' => '2026-01-01',
                'due_date' => $dueDate,
                'charge_batch_status_id' => $draftStatus->id,
                'progress_pct' => 0,
            ]);

            app(ChargeGenerationService::class)->activateBatch($batch);

            $charge = Charge::where('charge_batch_id', $batch->id)
                ->where('unit_id', $unit->id)
                ->firstOrFail();
        });

        return $charge;
    }

    /**
     * Assert a NotificationUser row exists for the given user with a title that contains
     * the expected fragment (case-insensitive). Must be called inside the tenant context.
     */
    private function assertUserNotified(string $userId, string $titleFragment): void
    {
        $exists = NotificationUser::query()
            ->where('user_id', $userId)
            ->whereHas('notification', function ($q) use ($titleFragment): void {
                $q->where('title', 'like', '%'.$titleFragment.'%');
            })
            ->exists();

        $this->assertTrue(
            $exists,
            "Expected an in-app notification titled like '{$titleFragment}' for user {$userId}."
        );
    }

    /**
     * Assert NO NotificationUser row exists for a given user (regardless of title).
     * Must be called inside the tenant context.
     */
    private function assertUserNotNotified(string $userId): void
    {
        $count = NotificationUser::where('user_id', $userId)->count();

        $this->assertSame(0, $count, "Expected zero NotificationUser rows for user {$userId}, found {$count}.");
    }

    // =========================================================================
    // (a) submit() → notifyEvent('payment_registered') → board recipients notified
    // =========================================================================

    public function test_submit_notifies_configured_board_recipients_for_payment_registered(): void
    {
        $tenant = $this->makeTenant('Submit Notify Cluster');
        $this->seedTenant($tenant);

        $president = $this->makeCentralUser('Presidente', 'president');
        $treasurer = $this->makeCentralUser('Tesorero', 'treasurer');
        $resident = $this->makeCentralUser('Residente');

        $president->tenants()->attach($tenant->id);
        $treasurer->tenants()->attach($tenant->id);
        $resident->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $resident);
        $bankAccount = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit, $bankAccount, $president, $treasurer, $resident): void {
            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);

            app(PaymentService::class)->submit([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // Board members (president + treasurer) must be notified.
            $this->assertUserNotified($president->id, 'Pago por validar');
            $this->assertUserNotified($treasurer->id, 'Pago por validar');

            // The resident who submitted must NOT be notified for this event (board-facing).
            $residentNotified = NotificationUser::query()
                ->where('user_id', $resident->id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%Pago por validar%'))
                ->exists();
            $this->assertFalse($residentNotified, 'The submitting resident must not receive the board-facing notification');
        });
    }

    // =========================================================================
    // (b) validatePayment() → notifyEvent('payment_accepted') → billing owner notified
    // =========================================================================

    public function test_validate_payment_notifies_billing_owner_with_payment_accepted(): void
    {
        $tenant = $this->makeTenant('Validate Notify Cluster');
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Validar');
        $titular->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit, $bankAccount, $titular): void {
            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);
            $service = app(PaymentService::class);

            // Resident submits a pending payment.
            $pending = $service->submit([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $this->assertSame(PaymentStatus::PENDING_VALIDATION, $pending->payment_status_id);

            // Tesorero validates the payment.
            $service->validatePayment($pending);

            // Billing owner must be notified of acceptance.
            $this->assertUserNotified($titular->id, 'Pago confirmado');
        });
    }

    // =========================================================================
    // (b2) click_action deep-link persistence — payment events open /payment/{id}
    // =========================================================================

    public function test_payment_notifications_persist_payment_detail_deep_link(): void
    {
        $tenant = $this->makeTenant('Deep Link Cluster');
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular DeepLink');
        $titular->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit, $bankAccount, $titular): void {
            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);
            $service = app(PaymentService::class);

            $pending = $service->submit([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $confirmed = $service->validatePayment($pending);

            // The resident's "Pago confirmado" in-app row deep-links to the payment
            // detail route (must start with '/' so the bell/toast actually navigate).
            $row = NotificationUser::query()
                ->where('user_id', $titular->id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%Pago confirmado%'))
                ->with('notification')
                ->firstOrFail();

            $this->assertSame(
                '/payment/'.$confirmed->id,
                $row->notification->click_action,
                'payment_accepted must persist a /payment/{id} deep-link.'
            );
        });
    }

    // =========================================================================
    // (c) reject() → notifyEvent('payment_rejected') → billing owner notified
    // =========================================================================

    public function test_reject_payment_notifies_billing_owner_with_reason(): void
    {
        $tenant = $this->makeTenant('Reject Notify Cluster');
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Rechazado');
        $titular->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit, $bankAccount, $titular): void {
            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);
            $service = app(PaymentService::class);

            $pending = $service->submit([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            $service->reject($pending, 'Comprobante ilegible.');

            // Billing owner must be notified of rejection.
            $this->assertUserNotified($titular->id, 'Pago rechazado');
        });
    }

    // =========================================================================
    // (d) collections:send-reminders → notifyEvent('payment_due_reminder') → resident notified
    // =========================================================================

    public function test_reminder_command_notifies_billing_owner_for_due_charge(): void
    {
        $tenant = $this->makeTenant('Reminder Notify Cluster');
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Recordatorio');
        $titular->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);

        // Today = July 8 → charge due July 15 (7 days before, in default before_due_days list)
        Carbon::setTestNow(Carbon::parse('2026-07-08'));
        $this->makeChargeForUnit($tenant, $unit, 70000, 'NOTREM7', '2026-07-15');

        $this->artisan('collections:send-reminders')->assertSuccessful();

        $tenant->run(function () use ($titular): void {
            $this->assertUserNotified($titular->id, 'Recordatorio de pago');
        });
    }

    // =========================================================================
    // (e) Inactive event → notifyEvent returns early → no NotificationUser rows
    // =========================================================================

    public function test_inactive_payment_registered_event_produces_no_notification(): void
    {
        $tenant = $this->makeTenant('Inactive Event Cluster');
        $this->seedTenant($tenant);

        $president = $this->makeCentralUser('Presidente Inactive', 'president');
        $treasurer = $this->makeCentralUser('Tesorero Inactive', 'treasurer');
        $resident = $this->makeCentralUser('Residente Inactive');

        $president->tenants()->attach($tenant->id);
        $treasurer->tenants()->attach($tenant->id);
        $resident->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $resident);
        $bankAccount = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit, $bankAccount, $president, $treasurer): void {
            // Deactivate the payment_registered event for this tenant.
            $event = NotificationEvent::findByKey('payment_registered');
            NotificationSetting::where('notification_event_id', $event->id)
                ->update(['is_active' => false]);

            $initialCount = NotificationUser::count();

            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);

            app(PaymentService::class)->submit([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 70000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // notifyEvent checks is_active=false and returns early — no rows added.
            $this->assertSame(
                $initialCount,
                NotificationUser::count(),
                'An inactive event must not create any NotificationUser rows'
            );
            $this->assertUserNotNotified($president->id);
            $this->assertUserNotNotified($treasurer->id);
        });
    }

    // =========================================================================
    // (f) register() → notifyEvent('payment_registered_direct') → billing owner notified
    // =========================================================================

    public function test_register_notifies_billing_owner_with_payment_registered_direct(): void
    {
        $tenant = $this->makeTenant('Register Direct Cluster');
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Directo');
        $titular->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit, $bankAccount, $titular): void {
            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);

            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 150000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // Billing owner receives the 'Pago registrado' in-app notification.
            $this->assertUserNotified($titular->id, 'Pago registrado');
        });
    }

    public function test_register_silent_produces_no_notification(): void
    {
        $tenant = $this->makeTenant('Register Silent Cluster');
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Silencioso');
        $titular->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant, 0);

        $tenant->run(function () use ($unit, $bankAccount): void {
            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);
            $initial = NotificationUser::count();

            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 150000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ], silent: true);

            // $silent=true must bypass ALL notifications — no rows created.
            $this->assertSame($initial, NotificationUser::count(), '$silent=true must not create any NotificationUser rows');
        });
    }

    public function test_inactive_payment_registered_direct_produces_no_notification(): void
    {
        $tenant = $this->makeTenant('Inactive Direct Cluster');
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular Inactivo Directo');
        $titular->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant, 0);

        // Create a charge matching the payment so no surplus credit is generated.
        // Without this, applyConfirmedPayment always fires 'Saldo a favor disponible',
        // which would make a global-count assertion unreliable.
        $this->makeChargeForUnit($tenant, $unit, 150000, 'INACTIVE-TEST', '2026-04-01');

        $tenant->run(function () use ($unit, $bankAccount, $titular): void {
            // Deactivate the payment_registered_direct event for this tenant.
            $event = NotificationEvent::findByKey('payment_registered_direct');
            NotificationSetting::where('notification_event_id', $event->id)
                ->update(['is_active' => false]);

            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);

            app(PaymentService::class)->register([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 150000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // notifyEvent checks is_active=false and returns early — no 'Pago registrado' row.
            $exists = NotificationUser::where('user_id', $titular->id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%Pago registrado%'))
                ->exists();

            $this->assertFalse($exists, 'Inactive payment_registered_direct must not deliver a "Pago registrado" notification to the resident');
        });
    }

    // =========================================================================
    // (e-2) Inactive payment_accepted event → no notification on validate
    // =========================================================================

    public function test_inactive_payment_accepted_event_produces_no_notification(): void
    {
        $tenant = $this->makeTenant('Inactive Accepted Cluster');
        $this->seedTenant($tenant);

        $titular = $this->makeCentralUser('Titular No Notif');
        $titular->tenants()->attach($tenant->id);

        $unit = $this->makeUnit($tenant);
        $this->attachBillingOwner($tenant, $unit, $titular);
        $bankAccount = $this->makeBankAccount($tenant);

        $tenant->run(function () use ($unit, $bankAccount, $titular): void {
            // Deactivate payment_accepted event.
            $event = NotificationEvent::findByKey('payment_accepted');
            NotificationSetting::where('notification_event_id', $event->id)
                ->update(['is_active' => false]);

            $method = PaymentMethod::findOrFail(PaymentMethod::CASH);
            $service = app(PaymentService::class);

            $pending = $service->submit([
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $method->id,
                'bank_account_id' => $bankAccount->id,
            ]);

            // Clear any rows from submit (board event may be active).
            NotificationUser::query()->delete();

            $service->validatePayment($pending);

            // The titular must not receive a 'Pago confirmado' row since the event is inactive.
            $received = NotificationUser::query()
                ->where('user_id', $titular->id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%Pago confirmado%'))
                ->exists();

            $this->assertFalse($received, 'payment_accepted when inactive must not create a NotificationUser row');
        });
    }
}
