<?php

declare(strict_types=1);

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\PaymentMethod;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Jobs\DeliverChannelMessageJob;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S10 — notification wiring for the 11 amenity_reservation.* events.
 *
 * Covers: created→admin/board (configurable president+treasurer + fixed
 * administrator), approved/rejected/cancelled→resident (+ board for cancelled),
 * payment_required on create/approve of a costed amenity, deposit_confirmed vs
 * payment_confirmed distinguished by amount_outstanding, surcharge_added,
 * payment_rejected (Payment observer hook), the per-tenant is_active gate, and
 * that a payment reversal never re-fires a settlement notification.
 */
final class AmenityReservationNotificationWiringTest extends TestCase
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

    private function tenantWithFlagEnabled(): Tenant
    {
        $tenant = $this->makeTenant('AmenityNotify '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        return $tenant;
    }

    private function makeRoleUser(Tenant $tenant, string $roleSlug): User
    {
        $user = User::create([
            'name' => ucfirst($roleSlug).' '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->assignRole($roleSlug);
        $user->tenants()->attach($tenant->id);

        return $user;
    }

    private function unit(): Unit
    {
        return Unit::factory()->create([
            'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
            'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            // Force a unique number: UnitFactory's number is optional()->numerify('###')
            // (~50% null), so multiple units per tenant collide on unit_identity_unique
            // (all-NULL identity tuple) ~25% of the time — pre-existing flakiness.
            'number' => (string) random_int(100000, 999999),
        ]);
    }

    private function attachResident(Unit $unit, User $user): void
    {
        UnitUser::create([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'unit_relationship_id' => UnitRelationship::OWNER,
            'is_billing_owner' => true,
        ]);
    }

    private function amenity(array $overrides = []): Amenity
    {
        return Amenity::create(array_merge([
            'name' => 'Amenidad '.uniqid(),
            'capacity' => 10,
            'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'reservation_mode_id' => ReservationMode::BY_DAY,
        ], $overrides));
    }

    private function makeBankAccount(Tenant $tenant, bool $primary): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $primary): void {
            $account = BankAccount::create([
                'name' => 'Cuenta '.uniqid(),
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => Catalog::id(BankAccountPurpose::class, 'Operativa'),
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => $primary,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    /** A future Wednesday — deterministic, avoids weekday flakiness. */
    private function futureDate(): Carbon
    {
        return Carbon::now()->addWeek()->next(Carbon::WEDNESDAY);
    }

    /** Must be called INSIDE the tenant context. */
    private function assertUserNotified(string $userId, string $titleFragment): void
    {
        $exists = NotificationUser::query()
            ->where('user_id', $userId)
            ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%'.$titleFragment.'%'))
            ->exists();

        $this->assertTrue($exists, "Expected an in-app notification titled like '{$titleFragment}' for user {$userId}.");
    }

    /** Must be called INSIDE the tenant context. */
    private function assertUserNotNotifiedWithTitle(string $userId, string $titleFragment): void
    {
        $exists = NotificationUser::query()
            ->where('user_id', $userId)
            ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%'.$titleFragment.'%'))
            ->exists();

        $this->assertFalse($exists, "Did not expect a notification titled like '{$titleFragment}' for user {$userId}.");
    }

    /** Must be called INSIDE the tenant context. */
    private function assertUserNotifiedWithClickAction(string $userId, string $titleFragment, string $clickAction): void
    {
        $row = NotificationUser::query()
            ->where('user_id', $userId)
            ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%'.$titleFragment.'%'))
            ->with('notification')
            ->first();

        $this->assertNotNull($row, "Expected an in-app notification titled like '{$titleFragment}' for user {$userId}.");
        $this->assertSame($clickAction, $row->notification->click_action);
    }

    /**
     * S10 (email channel) — explicitly force channel_email=true for one event's
     * notification_setting row. Fresh test tenants already inherit
     * channel_email=true via the CatalogSeeder default_channels flip (task 1.2)
     * + TenantNotificationSettingSeeder's firstOrCreate default, but the tests
     * below set it explicitly so they assert the runtime toggle itself — the
     * SAME row NotificationSettingService::channelsFromSetting() reads — rather
     * than depending on seeder defaults staying as they are today. Must be
     * called INSIDE the tenant context.
     */
    private function enableEmailChannel(string $eventKey): void
    {
        $event = NotificationEvent::findByKey($eventKey);
        NotificationSetting::where('notification_event_id', $event->id)->update(['channel_email' => true]);
    }

    // =========================================================================
    // (a) amenity_reservation.created → board (president+treasurer) + administrator,
    //     NEVER the creating resident
    // =========================================================================

    public function test_create_notifies_board_and_administrator_but_not_the_creating_resident(): void
    {
        $tenant = $this->tenantWithFlagEnabled();

        $resident = $this->makeUser($tenant);
        $president = $this->makeRoleUser($tenant, 'president');
        $treasurer = $this->makeRoleUser($tenant, 'treasurer');
        $administrator = $this->makeRoleUser($tenant, 'administrator');

        $residentToken = $resident->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($residentToken, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);

        $this->inTenant($tenant, function () use ($president, $treasurer, $administrator, $resident): void {
            $this->assertUserNotified($president->id, 'Nueva reservación');
            $this->assertUserNotified($treasurer->id, 'Nueva reservación');
            $this->assertUserNotified($administrator->id, 'Nueva reservación');
            $this->assertUserNotNotifiedWithTitle($resident->id, 'Nueva reservación');
        });
    }

    // =========================================================================
    // (b) amenity_reservation.payment_required fires on create() of a costed
    //     amenity, targeting the resident (not the board)
    // =========================================================================

    public function test_create_of_costed_amenity_also_notifies_resident_payment_required(): void
    {
        $tenant = $this->tenantWithFlagEnabled();

        $resident = $this->makeUser($tenant);
        $residentToken = $resident->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        [$amenityId, $unitId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity(['has_cost' => true, 'cost_amount' => 100000]);
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id];
        });

        $this->tenantRequest($residentToken, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);

        $this->inTenant($tenant, function () use ($resident): void {
            $this->assertUserNotified($resident->id, 'Pago requerido');
            // The board-facing 'created' notification must not reach the resident.
            $this->assertUserNotNotifiedWithTitle($resident->id, 'Nueva reservación');
        });
    }

    // =========================================================================
    // (c) amenity_reservation.approved → resident
    // =========================================================================

    public function test_approve_notifies_resident(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);
        $boardToken = $board->createToken('board', ['update.amenity_reservation'])->plainTextToken;

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
                'holds_slot' => true,
                'cost_amount' => null,
                'deposit_amount' => null,
                'amount_outstanding' => 0,
            ]);

            return $reservation->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/approve")
            ->assertStatus(200);

        $this->inTenant($tenant, function () use ($resident): void {
            $this->assertUserNotified($resident->id, 'Reservación aprobada');
        });
    }

    // =========================================================================
    // (d) amenity_reservation.rejected → resident, with the reason in copy
    // =========================================================================

    public function test_reject_notifies_resident_with_reason(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);
        $boardToken = $board->createToken('board', ['update.amenity_reservation'])->plainTextToken;

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
                'holds_slot' => true,
                'cost_amount' => null,
                'deposit_amount' => null,
                'amount_outstanding' => 0,
            ]);

            return $reservation->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/reject", [
                'rejected_reason' => 'La amenidad estará en mantenimiento.',
            ])
            ->assertStatus(200);

        $this->inTenant($tenant, function () use ($resident): void {
            $row = NotificationUser::query()
                ->where('user_id', $resident->id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%Reservación rechazada%'))
                ->with('notification')
                ->first();

            $this->assertNotNull($row);
            $this->assertStringContainsString('mantenimiento', $row->notification->message);
        });
    }

    // =========================================================================
    // (e) amenity_reservation.cancelled → resident (/my-reservations) AND board
    //     (/amenity-reservation) — two audiences, one event
    // =========================================================================

    public function test_cancel_notifies_resident_and_fixed_admin_roles_with_different_click_actions(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $president = $this->makeRoleUser($tenant, 'president');
        $board = $this->makeUser($tenant);
        $boardToken = $board->createToken('board', ['update.amenity_reservation'])->plainTextToken;

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => null,
                'deposit_amount' => null,
                'amount_outstanding' => 0,
            ]);

            return $reservation->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/cancel", [
                'cancellation_reason' => 'El residente lo solicitó por teléfono.',
            ])
            ->assertStatus(200);

        $this->inTenant($tenant, function () use ($resident, $president): void {
            $this->assertUserNotifiedWithClickAction($resident->id, 'Reservación cancelada', '/my-reservations');
            $this->assertUserNotifiedWithClickAction($president->id, 'Reservación cancelada', '/amenity-reservation');
        });
    }

    // =========================================================================
    // (f) amenity_reservation.surcharge_added → resident
    // =========================================================================

    public function test_surcharge_added_notifies_resident(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);
        $boardToken = $board->createToken('board', ['update.amenity_reservation'])->plainTextToken;

        [$reservationId, $conceptId] = $this->inTenant($tenant, function () use ($resident): array {
            $amenity = $this->amenity(['has_cost' => false]);
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => null,
                'deposit_amount' => null,
                'amount_outstanding' => 0,
            ]);

            $concept = ChargeConcept::create([
                'code' => 'INCIDENT_'.mt_rand(1000, 99999),
                'name' => 'Daños e incidentes',
                'charge_concept_category_id' => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'),
                'is_active' => true,
            ]);

            return [$reservation->id, $concept->id];
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/surcharges", [
                'charge_concept_id' => $conceptId,
                'amount' => 25000,
                'description' => 'Vidrio roto del área de asadores.',
            ])
            ->assertStatus(201);

        $this->inTenant($tenant, function () use ($resident): void {
            $this->assertUserNotified($resident->id, 'Cargo adicional aplicado');
        });
    }

    // =========================================================================
    // (g) deposit_confirmed vs payment_confirmed, distinguished by amount_outstanding
    // =========================================================================

    public function test_partial_deposit_earmark_payment_fires_deposit_confirmed_not_payment_confirmed(): void
    {
        $scn = $this->earmarkScenario([
            'cost_amount' => 100000,
            'requires_deposit' => true,
            'deposit_amount' => 30000,
            'deposit_due_days' => 5,
        ]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 30000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $this->inTenant($scn['tenant'], function () use ($scn): void {
            $this->assertUserNotified($scn['resident']->id, 'Depósito confirmado');
            // 'Reservación liquidada' is amenity_reservation.payment_confirmed's title —
            // must NOT fire on a deposit-only settle. (The unrelated, ALSO-fired
            // generic Collections 'Pago confirmado'/payment_accepted notification is
            // expected on every earmark validation and is not asserted against here.)
            $this->assertUserNotNotifiedWithTitle($scn['resident']->id, 'Reservación liquidada');
        });
    }

    public function test_settling_earmark_payment_fires_payment_confirmed(): void
    {
        $scn = $this->earmarkScenario(['cost_amount' => 50000]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $this->inTenant($scn['tenant'], function () use ($scn): void {
            $this->assertUserNotified($scn['resident']->id, 'Reservación liquidada');
            // Settling in one shot (no deposit required) must NOT also fire deposit_confirmed.
            $this->assertUserNotNotifiedWithTitle($scn['resident']->id, 'Depósito confirmado');
        });
    }

    /**
     * A confirmed-with-deposit reservation whose earmark payment is reversed
     * directly (/payment/{id}/cancel) reverts to PENDING_PAYMENT — a "wrong
     * transition" that must NOT re-fire (or fire) a settlement notification.
     */
    public function test_direct_payment_cancel_reversal_does_not_fire_a_settlement_notification(): void
    {
        $scn = $this->earmarkScenario([
            'cost_amount' => 100000,
            'requires_deposit' => true,
            'deposit_amount' => 30000,
            'deposit_due_days' => 5,
        ]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 30000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        // Clear the deposit_confirmed row generated above so the assertion below
        // only reflects what the REVERSAL itself produced.
        $this->inTenant($scn['tenant'], function () use ($scn): void {
            NotificationUser::where('user_id', $scn['resident']->id)->delete();
        });

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/cancel", ['reason' => 'Error de captura.'])
            ->assertStatus(200);

        $this->inTenant($scn['tenant'], function () use ($scn): void {
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $reservation->reservation_status_id);

            $this->assertSame(0, NotificationUser::where('user_id', $scn['resident']->id)->count());
        });
    }

    // =========================================================================
    // (h) amenity_reservation.payment_rejected — Payment observer hook
    // =========================================================================

    public function test_rejecting_an_amenity_earmark_payment_notifies_resident(): void
    {
        $scn = $this->earmarkScenario(['cost_amount' => 50000]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/reject", ['reason' => 'Comprobante ilegible.'])
            ->assertStatus(200);

        $this->inTenant($scn['tenant'], function () use ($scn): void {
            $this->assertUserNotified($scn['resident']->id, 'Pago de reservación rechazado');
        });
    }

    // =========================================================================
    // (i) Inactive event → notifyEvent returns early → no notification
    // =========================================================================

    public function test_inactive_approved_event_produces_no_notification(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);
        $boardToken = $board->createToken('board', ['update.amenity_reservation'])->plainTextToken;

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $event = NotificationEvent::findByKey('amenity_reservation.approved');
            NotificationSetting::where('notification_event_id', $event->id)->update(['is_active' => false]);

            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
                'holds_slot' => true,
                'cost_amount' => null,
                'deposit_amount' => null,
                'amount_outstanding' => 0,
            ]);

            return $reservation->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/approve")
            ->assertStatus(200);

        $this->inTenant($tenant, function () use ($resident): void {
            $this->assertUserNotNotifiedWithTitle($resident->id, 'Reservación aprobada');
        });
    }

    // =========================================================================
    // (j) blade_email dispatch coverage per event (S10 email channel)
    //
    // Mail::fake()/Mail::assertSent() does NOT apply here: BladeEmailChannel
    // sends via Mail::html(), which is not a Mailable — MailFake has no html()
    // method of its own and forwards the call straight to the real MailManager
    // (bypassing Mail::fake()'s tracking entirely), so nothing would ever be
    // recorded for assertSent() to find. Queue::fake() + assertPushed is the
    // correct assertion for "dispatch": it proves DeliverChannelMessageJob is
    // enqueued for the blade_email channel when channel_email=true. A separate
    // effect-level test (below) verifies a real send lands in the test
    // environment's MAIL_MAILER=array transport with QUEUE_CONNECTION=sync
    // (no faking), proving the full chain end-to-end for one representative
    // event — the real render/content contract itself is covered by
    // AmenityReservationEmailRenderTest (design §4.1, the load-bearing check).
    // =========================================================================

    /**
     * Also asserts the job's email_context — produced by the REAL
     * ReservationService::notifyReservationCreated() enrichment, not hand-fed
     * template vars — actually carries the unit_label/resident_name the
     * template needs (design §"Board email context identifies the unit and
     * resident"). Without this, a regression that stops populating those
     * context keys would still pass every other assertion here.
     */
    public function test_created_dispatches_blade_email_when_channel_enabled(): void
    {
        $tenant = $this->tenantWithFlagEnabled();

        $resident = $this->makeUser($tenant);
        $president = $this->makeRoleUser($tenant, 'president');
        $this->makeRoleUser($tenant, 'treasurer');
        $this->makeRoleUser($tenant, 'administrator');

        $residentToken = $resident->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        [$amenityId, $unitId, $unitLabel] = $this->inTenant($tenant, function () use ($resident): array {
            $this->enableEmailChannel('amenity_reservation.created');
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id, $unit->full_identifier];
        });

        Queue::fake();

        $this->tenantRequest($residentToken, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);

        Queue::assertPushed(
            DeliverChannelMessageJob::class,
            function (DeliverChannelMessageJob $job) use ($president, $resident, $unitLabel): bool {
                if ($job->channel !== 'blade_email' || $job->recipients !== [$president->email]) {
                    return false;
                }

                $context = (array) ($job->data['email_context'] ?? []);

                return ($context['unit_label'] ?? '') === $unitLabel
                    && ($context['resident_name'] ?? '') === $resident->name;
            },
        );
    }

    public function test_approved_dispatches_blade_email_when_channel_enabled(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);
        $boardToken = $board->createToken('board', ['update.amenity_reservation'])->plainTextToken;

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $this->enableEmailChannel('amenity_reservation.approved');
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
                'holds_slot' => true,
                'cost_amount' => null,
                'deposit_amount' => null,
                'amount_outstanding' => 0,
            ]);

            return $reservation->id;
        });

        Queue::fake();

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/approve")
            ->assertStatus(200);

        Queue::assertPushed(
            DeliverChannelMessageJob::class,
            fn (DeliverChannelMessageJob $job): bool => $job->channel === 'blade_email'
                && $job->recipients === [$resident->email],
        );
    }

    public function test_payment_confirmed_dispatches_blade_email_when_channel_enabled(): void
    {
        $scn = $this->earmarkScenario(['cost_amount' => 50000]);
        $this->inTenant($scn['tenant'], function (): void {
            $this->enableEmailChannel('amenity_reservation.payment_confirmed');
        });

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000))
            ->assertStatus(201)
            ->json('data.id');

        Queue::fake();

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        Queue::assertPushed(
            DeliverChannelMessageJob::class,
            fn (DeliverChannelMessageJob $job): bool => $job->channel === 'blade_email'
                && $job->recipients === [$scn['resident']->email],
        );
    }

    public function test_payment_rejected_dispatches_blade_email_when_channel_enabled(): void
    {
        $scn = $this->earmarkScenario(['cost_amount' => 50000]);
        $this->inTenant($scn['tenant'], function (): void {
            $this->enableEmailChannel('amenity_reservation.payment_rejected');
        });

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000))
            ->assertStatus(201)
            ->json('data.id');

        Queue::fake();

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/reject", ['reason' => 'Comprobante ilegible.'])
            ->assertStatus(200);

        Queue::assertPushed(
            DeliverChannelMessageJob::class,
            fn (DeliverChannelMessageJob $job): bool => $job->channel === 'blade_email'
                && $job->recipients === [$scn['resident']->email],
        );
    }

    public function test_surcharge_added_dispatches_blade_email_when_channel_enabled(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);
        $boardToken = $board->createToken('board', ['update.amenity_reservation'])->plainTextToken;

        [$reservationId, $conceptId] = $this->inTenant($tenant, function () use ($resident): array {
            $this->enableEmailChannel('amenity_reservation.surcharge_added');
            $amenity = $this->amenity(['has_cost' => false]);
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => null,
                'deposit_amount' => null,
                'amount_outstanding' => 0,
            ]);

            $concept = ChargeConcept::create([
                'code' => 'INCIDENT_'.mt_rand(1000, 99999),
                'name' => 'Daños e incidentes',
                'charge_concept_category_id' => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'),
                'is_active' => true,
            ]);

            return [$reservation->id, $concept->id];
        });

        Queue::fake();

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/surcharges", [
                'charge_concept_id' => $conceptId,
                'amount' => 25000,
                'description' => 'Vidrio roto del área de asadores.',
            ])
            ->assertStatus(201);

        Queue::assertPushed(
            DeliverChannelMessageJob::class,
            fn (DeliverChannelMessageJob $job): bool => $job->channel === 'blade_email'
                && $job->recipients === [$resident->email],
        );
    }

    /**
     * Effect-level proof (no Queue/Mail faking): with QUEUE_CONNECTION=sync,
     * DeliverChannelMessageJob runs inline, and with MAIL_MAILER=array the
     * message lands in Symfony's in-memory ArrayTransport instead of any real
     * network I/O — a real, renderable email left BladeEmailChannel for one
     * representative event, end-to-end.
     */
    public function test_approved_email_lands_in_the_array_mail_transport(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);
        $boardToken = $board->createToken('board', ['update.amenity_reservation'])->plainTextToken;

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $this->enableEmailChannel('amenity_reservation.approved');
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
                'holds_slot' => true,
                'cost_amount' => null,
                'deposit_amount' => null,
                'amount_outstanding' => 0,
            ]);

            return $reservation->id;
        });

        /** @var ArrayTransport $transport */
        $transport = Mail::getSymfonyTransport();
        $transport->flush();

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/approve")
            ->assertStatus(200);

        $sent = $transport->messages();
        $this->assertGreaterThan(0, $sent->count(), 'Expected a real email in the array mail transport.');

        $message = $sent->last()->getOriginalMessage();
        $this->assertSame('Reservación aprobada', $message->getSubject());
        $this->assertStringContainsString($resident->email, (string) $message->getTo()[0]->getAddress());
    }

    /**
     * Same effect-level proof as the approved test above, but for `created` —
     * and, unlike that one, asserts the rendered HTML actually contains the
     * unit_label/resident_name produced by the REAL
     * ReservationService::notifyReservationCreated() enrichment (design §"Board
     * email context identifies the unit and resident"). This is the strongest
     * check available that those context keys survive the full production path
     * (service → notifyEvent → DeliverChannelMessageJob → BladeEmailChannel →
     * Blade render), not just a hand-fed view() call.
     */
    public function test_created_email_lands_in_the_array_mail_transport_with_unit_and_resident_identity(): void
    {
        $tenant = $this->tenantWithFlagEnabled();

        $resident = $this->makeUser($tenant);
        $president = $this->makeRoleUser($tenant, 'president');
        $this->makeRoleUser($tenant, 'treasurer');
        $this->makeRoleUser($tenant, 'administrator');

        $residentToken = $resident->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        [$amenityId, $unitId, $unitLabel] = $this->inTenant($tenant, function () use ($resident): array {
            $this->enableEmailChannel('amenity_reservation.created');
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return [$amenity->id, $unit->id, $unit->full_identifier];
        });

        /** @var ArrayTransport $transport */
        $transport = Mail::getSymfonyTransport();
        $transport->flush();

        $this->tenantRequest($residentToken, $tenant)
            ->postJson('/api/amenity_reservation', [
                'amenity_id' => $amenityId,
                'unit_id' => $unitId,
                'reservation_date' => $this->futureDate()->toDateString(),
            ])
            ->assertStatus(201);

        // `created` fans out one email per recipient (president, treasurer,
        // administrator each get their own DeliverChannelMessageJob), so find
        // the president's message rather than assuming array order.
        $sent = $transport->messages();
        $this->assertGreaterThan(0, $sent->count(), 'Expected a real email in the array mail transport.');

        $presidentMessage = $sent->first(
            fn ($sentMessage) => str_contains((string) $sentMessage->getOriginalMessage()->getTo()[0]->getAddress(), $president->email),
        );
        $this->assertNotNull($presidentMessage, 'Expected an email addressed to the president in the array mail transport.');

        $message = $presidentMessage->getOriginalMessage();
        $this->assertSame('Nueva reservación de amenidad', $message->getSubject());

        $html = (string) $message->getHtmlBody();
        $this->assertStringContainsString($unitLabel, $html, 'Expected the real unit_label produced by ReservationService to reach the rendered email.');
        $this->assertStringContainsString($resident->name, $html, 'Expected the real resident_name produced by ReservationService to reach the rendered email.');
    }

    // =========================================================================
    // Earmark payment scenario helper (mirrors AmenityReservationEarmarkTest)
    // =========================================================================

    /**
     * @param  array<string, mixed>  $amenityOverrides
     * @return array{tenant: Tenant, resident: User, residentToken: string,
     *     treasurerToken: string, account: BankAccount, reservationId: string}
     */
    private function earmarkScenario(array $amenityOverrides = []): array
    {
        $tenant = $this->tenantWithFlagEnabled();

        $resident = $this->makeUser($tenant);
        $unit = $this->inTenant($tenant, function () use ($resident): Unit {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return $unit;
        });

        $account = $this->makeBankAccount($tenant, primary: false);
        $this->makeBankAccount($tenant, primary: true);

        $treasurer = $this->makeUser($tenant);

        $reservationId = $tenant->run(function () use ($unit, $account, $amenityOverrides): string {
            $amenity = $this->amenity(array_merge([
                'has_cost' => true,
                'cost_amount' => 50000,
                'requires_deposit' => false,
                'deposit_amount' => null,
                'deposit_due_days' => null,
                'bank_account_id' => $account->id,
            ], $amenityOverrides));

            $costRaw = (int) $amenity->getAttributes()['cost_amount'];

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
                'holds_slot' => true,
                'cost_amount' => $costRaw,
                'deposit_amount' => $amenity->getAttributes()['deposit_amount'],
                'amount_outstanding' => $costRaw,
            ]);

            return $reservation->id;
        });

        return [
            'tenant' => $tenant,
            'unit' => $unit,
            'resident' => $resident,
            'residentToken' => $resident->createToken('resident', ['create.payment_submission'])->plainTextToken,
            'treasurerToken' => $treasurer->createToken('treasurer', ['update.payment'])->plainTextToken,
            'account' => $account,
            'reservationId' => $reservationId,
        ];
    }

    /**
     * @param  array{unit: Unit, account: BankAccount, reservationId: string}  $scn
     * @return array<string, mixed>
     */
    private function submitPayload(array $scn, int $amount): array
    {
        return [
            'unit_id' => $scn['unit']->id,
            'paid_at' => '2026-07-01',
            'total_amount' => $amount,
            // TRANSFER, not CASH (Slice C): resident self-submissions cannot use cash.
            'payment_method_id' => PaymentMethod::TRANSFER,
            'bank_account_id' => $scn['account']->id,
            'earmark_type' => 'amenity_reservation',
            'earmark_id' => $scn['reservationId'],
            // Any non-cash method REQUIRES a comprobante at the controller
            // (pre-existing §8.7.1 r5 gate, unrelated to Slice C but now
            // reachable since these tests no longer submit as cash). A REAL
            // fake image (not ::create(), which reports a size/mime without
            // real bytes) — Spatie MediaLibrary sniffs actual file content.
            'receipt' => UploadedFile::fake()->image('comprobante.png'),
        ];
    }
}
