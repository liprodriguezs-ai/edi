<?php

declare(strict_types=1);

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S11 — amenities:cancel-expired-reservations: expiry (auto-cancel past
 * deadline, reversing any CONFIRMED earmark payment) + payment reminders.
 * Mirrors RefreshChargeStatusesCommand's per-tenant cron test style
 * (Carbon::setTestNow for deterministic deadlines).
 */
final class CancelExpiredReservationsCommandTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function tenantWithFlagEnabled(string $name = 'Cluster'): Tenant
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        return $tenant;
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

    /**
     * A resident self-submission (Slice C: cash is admin-register-only) needs
     * a non-cash method, which in turn REQUIRES a comprobante at the
     * controller (pre-existing §8.7.1 r5 gate). A REAL fake image (not
     * ::create(), which reports a size/mime without real bytes) — Spatie
     * MediaLibrary sniffs actual file content.
     */
    private function fakeReceipt(): UploadedFile
    {
        return UploadedFile::fake()->image('comprobante.png');
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

    /** A fresh amenity + a PENDING_PAYMENT reservation holding its slot. */
    private function pendingPaymentReservation(Unit $unit, ?Carbon $paymentDeadline): AmenityReservation
    {
        $amenity = $this->amenity(['has_cost' => true, 'cost_amount' => 50000]);

        return AmenityReservation::factory()->create([
            'amenity_id' => $amenity->id,
            'unit_id' => $unit->id,
            'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
            'holds_slot' => true,
            'cost_amount' => 50000,
            'amount_outstanding' => 50000,
            'payment_deadline' => $paymentDeadline,
        ]);
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
    private function assertUserNotNotified(string $userId, string $titleFragment): void
    {
        $exists = NotificationUser::query()
            ->where('user_id', $userId)
            ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%'.$titleFragment.'%'))
            ->exists();

        $this->assertFalse($exists, "Did not expect a notification titled like '{$titleFragment}' for user {$userId}.");
    }

    /** Must be called INSIDE the tenant context. */
    private function notificationCount(string $userId, string $titleFragment): int
    {
        return NotificationUser::query()
            ->where('user_id', $userId)
            ->whereHas('notification', fn ($q) => $q->where('title', 'like', '%'.$titleFragment.'%'))
            ->count();
    }

    private function makeBankAccount(string $name, bool $primary): BankAccount
    {
        return BankAccount::create([
            'name' => $name,
            'bank_account_type_id' => BankAccountType::CASH,
            'bank_account_purpose_id' => Catalog::id(BankAccountPurpose::class, 'Operativa'),
            'currency' => 'MXN',
            'initial_balance' => 0,
            'current_balance' => 0,
            'is_primary' => $primary,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // (A) PENDING_PAYMENT past its payment_deadline
    // =========================================================================

    public function test_expires_a_pending_payment_reservation_past_its_payment_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $reservation = $this->pendingPaymentReservation($unit, Carbon::parse('2026-07-08 12:00:00', 'UTC'));

            return $reservation->id;
        });

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId, $resident): void {
            $reservation = AmenityReservation::findOrFail($reservationId);

            $this->assertSame(ReservationStatus::CANCELLED, $reservation->reservation_status_id);
            $this->assertFalse((bool) $reservation->holds_slot);
            $this->assertStringContainsString('Vencimiento', (string) $reservation->cancellation_reason);
            $this->assertNotNull($reservation->cancelled_at);

            $this->assertUserNotified($resident->id, 'Reservación expirada');
        });
    }

    // =========================================================================
    // (B) CONFIRMED with outstanding>0 past balance_due_date — reverses the
    //     confirmed deposit payment
    // =========================================================================

    public function test_expires_a_confirmed_reservation_with_outstanding_balance_past_due_and_reverses_the_deposit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $treasurer = $this->makeUser($tenant);

        $unit = $this->inTenant($tenant, function () use ($resident): Unit {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return $unit;
        });

        $account = $this->inTenant($tenant, fn () => $this->makeBankAccount('Cuenta amenidad', primary: false));
        $this->inTenant($tenant, fn () => $this->makeBankAccount('Caja general', primary: true));

        $reservationId = $this->inTenant($tenant, function () use ($unit, $account): string {
            $amenity = $this->amenity([
                'has_cost' => true,
                'cost_amount' => 100000,
                'requires_deposit' => true,
                'deposit_amount' => 30000,
                'deposit_due_days' => 5,
                'bank_account_id' => $account->id,
            ]);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
                'holds_slot' => true,
                'cost_amount' => 100000,
                'deposit_amount' => 30000,
                'amount_outstanding' => 100000,
            ]);

            return $reservation->id;
        });

        $residentToken = $resident->createToken('resident', ['create.payment_submission'])->plainTextToken;
        $treasurerToken = $treasurer->createToken('treasurer', ['update.payment'])->plainTextToken;

        $paymentId = $this->tenantRequest($residentToken, $tenant)
            ->post('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 30000,
                // TRANSFER, not CASH (Slice C): these are all resident self-submissions
                // via payment/submit — cash is admin-register-only. Payment method is
                // incidental to what this test suite actually exercises (expiry timing).
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $account->id,
                'earmark_type' => 'amenity_reservation',
                'earmark_id' => $reservationId,
                // Any non-cash method REQUIRES a comprobante at the controller
                // (pre-existing §8.7.1 r5 gate, unrelated to Slice C).
                'receipt' => $this->fakeReceipt(),
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($treasurerToken, $tenant)
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        // Sanity: confirmed with the deposit-only balance still owing, and the
        // bank credited — before forcing the due date into the past below.
        $this->inTenant($tenant, function () use ($reservationId, $account): void {
            $reservation = AmenityReservation::findOrFail($reservationId);
            $this->assertSame(ReservationStatus::CONFIRMED, $reservation->reservation_status_id);
            $this->assertSame(70000, (int) $reservation->getAttributes()['amount_outstanding']);
            $this->assertNotNull($reservation->balance_due_date);
            $this->assertSame(30000, (int) BankAccount::findOrFail($account->id)->getAttributes()['current_balance']);
        });

        // Force the balance_due_date into the past (bypassing the natural
        // passage of time) so the cron's prefilter selects it.
        $this->inTenant($tenant, function () use ($reservationId): void {
            AmenityReservation::where('id', $reservationId)->update(['balance_due_date' => '2026-06-01']);
        });

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId, $paymentId, $account): void {
            // FIX (E2E QA — stale amount_outstanding on a terminal reservation):
            // amount_outstanding freezes at its last ACTIVE value (70000) instead
            // of being resurrected to the full 100000 cost — expire() (the cron
            // counterpart to cancel()) now writes CANCELLED BEFORE reversing the
            // earmark deposit payment, so syncFromChargeApplication's terminal-
            // status guard blocks the cascade from recomputing amount_outstanding.
            $reservation = AmenityReservation::findOrFail($reservationId);
            $this->assertSame(ReservationStatus::CANCELLED, $reservation->reservation_status_id);
            $this->assertFalse((bool) $reservation->holds_slot);
            $this->assertSame(70000, (int) $reservation->getAttributes()['amount_outstanding']);

            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::CANCELLED, $payment->payment_status_id);

            $this->assertSame(0, (int) BankAccount::findOrFail($account->id)->getAttributes()['current_balance']);
        });
    }

    // =========================================================================
    // (C) Does NOT expire a fully-paid CONFIRMED or a not-yet-due one
    // =========================================================================

    public function test_does_not_expire_a_fully_paid_confirmed_or_a_not_yet_due_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);

        [$fullyPaidId, $notYetDueId, $pendingFutureId] = $this->inTenant($tenant, function () use ($resident): array {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            $fullyPaidAmenity = $this->amenity(['has_cost' => true, 'cost_amount' => 50000]);
            $fullyPaid = AmenityReservation::factory()->create([
                'amenity_id' => $fullyPaidAmenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => 50000,
                'amount_outstanding' => 0,
                // Even a stale balance_due_date must never re-expire a settled reservation.
                'balance_due_date' => '2026-01-01',
            ]);

            $notYetDueAmenity = $this->amenity(['has_cost' => true, 'cost_amount' => 100000]);
            $notYetDue = AmenityReservation::factory()->create([
                'amenity_id' => $notYetDueAmenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => 100000,
                'amount_outstanding' => 70000,
                'balance_due_date' => '2026-08-01',
            ]);

            $pendingFuture = $this->pendingPaymentReservation($unit, Carbon::parse('2026-07-20 12:00:00', 'UTC'));

            return [$fullyPaid->id, $notYetDue->id, $pendingFuture->id];
        });

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($fullyPaidId, $notYetDueId, $pendingFutureId): void {
            $fullyPaid = AmenityReservation::findOrFail($fullyPaidId);
            $this->assertSame(ReservationStatus::CONFIRMED, $fullyPaid->reservation_status_id);
            $this->assertTrue((bool) $fullyPaid->holds_slot);

            $notYetDue = AmenityReservation::findOrFail($notYetDueId);
            $this->assertSame(ReservationStatus::CONFIRMED, $notYetDue->reservation_status_id);
            $this->assertTrue((bool) $notYetDue->holds_slot);

            $pendingFuture = AmenityReservation::findOrFail($pendingFutureId);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $pendingFuture->reservation_status_id);
            $this->assertTrue((bool) $pendingFuture->holds_slot);
        });
    }

    // =========================================================================
    // (D) No-op for a tenant with the flag OFF
    // =========================================================================

    public function test_is_a_no_op_for_a_tenant_with_the_flag_off(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        // Deliberately NOT calling tenantWithFlagEnabled() — the flag stays
        // at its seeded default (false).
        $tenant = $this->makeTenant('Disabled Cluster');
        $this->seedTenant($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $unit = $this->unit();

            return $this->pendingPaymentReservation($unit, Carbon::parse('2026-07-08 12:00:00', 'UTC'))->id;
        });

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId): void {
            $reservation = AmenityReservation::findOrFail($reservationId);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $reservation->reservation_status_id);
            $this->assertTrue((bool) $reservation->holds_slot);
        });
    }

    // =========================================================================
    // (E) Idempotent — a second run does nothing
    // =========================================================================

    public function test_a_second_run_is_idempotent_and_does_not_double_notify(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return $this->pendingPaymentReservation($unit, Carbon::parse('2026-07-08 12:00:00', 'UTC'))->id;
        });

        Artisan::call('amenities:cancel-expired-reservations');
        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId, $resident): void {
            $reservation = AmenityReservation::findOrFail($reservationId);
            $this->assertSame(ReservationStatus::CANCELLED, $reservation->reservation_status_id);

            $this->assertSame(1, $this->notificationCount($resident->id, 'Reservación expirada'));
        });
    }

    // =========================================================================
    // (F) Reminders — within the window vs. out of it
    // =========================================================================

    public function test_reminder_fires_within_the_window_but_not_out_of_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $residentWithin = $this->makeUser($tenant);
        $residentOutside = $this->makeUser($tenant);

        [$reservationWithinId, $reservationOutsideId] = $this->inTenant($tenant, function () use ($residentWithin, $residentOutside): array {
            $unitWithin = $this->unit();
            $this->attachResident($unitWithin, $residentWithin);
            // Exactly 1 day away (tenant tz) — inside the reminder window.
            $within = $this->pendingPaymentReservation($unitWithin, Carbon::parse('2026-07-11 12:00:00', 'UTC'));

            $unitOutside = $this->unit();
            $this->attachResident($unitOutside, $residentOutside);
            // 5 days away — outside the reminder window.
            $outside = $this->pendingPaymentReservation($unitOutside, Carbon::parse('2026-07-15 12:00:00', 'UTC'));

            return [$within->id, $outside->id];
        });

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($residentWithin, $residentOutside, $reservationWithinId, $reservationOutsideId): void {
            $this->assertUserNotified($residentWithin->id, 'Recordatorio de pago');
            $this->assertUserNotNotified($residentOutside->id, 'Recordatorio de pago');

            // Neither reservation has actually lapsed — a reminder must never
            // also expire the reservation it reminds about.
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, AmenityReservation::findOrFail($reservationWithinId)->reservation_status_id);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, AmenityReservation::findOrFail($reservationOutsideId)->reservation_status_id);
        });
    }

    // =========================================================================
    // (G) Per-tenant iteration — only the right tenant's reservation is touched
    // =========================================================================

    public function test_per_tenant_iteration_only_touches_the_expirable_tenants_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenantA = $this->tenantWithFlagEnabled('Tenant A');
        $tenantB = $this->tenantWithFlagEnabled('Tenant B');

        $reservationIdA = $this->inTenant($tenantA, function (): string {
            $unit = $this->unit();

            // Past its deadline — must expire.
            return $this->pendingPaymentReservation($unit, Carbon::parse('2026-07-08 12:00:00', 'UTC'))->id;
        });

        $reservationIdB = $this->inTenant($tenantB, function (): string {
            $unit = $this->unit();

            // Not yet due — must NOT expire.
            return $this->pendingPaymentReservation($unit, Carbon::parse('2026-08-01 12:00:00', 'UTC'))->id;
        });

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenantA, function () use ($reservationIdA): void {
            $this->assertSame(ReservationStatus::CANCELLED, AmenityReservation::findOrFail($reservationIdA)->reservation_status_id);
        });

        $this->inTenant($tenantB, function () use ($reservationIdB): void {
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, AmenityReservation::findOrFail($reservationIdB)->reservation_status_id);
        });
    }

    // =========================================================================
    // (H) Timezone boundary — a deadline "past" in UTC but not in tenant tz
    // =========================================================================

    public function test_timezone_boundary_is_not_expired_early_in_utc(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        // Default tenant timezone is America/Mexico_City (UTC-6, no DST).

        $reservationId = $this->inTenant($tenant, function (): string {
            $unit = $this->unit();
            $amenity = $this->amenity(['has_cost' => true, 'cost_amount' => 100000]);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => 100000,
                'amount_outstanding' => 30000,
                'balance_due_date' => '2026-07-07',
            ]);

            return $reservation->id;
        });

        // 2026-07-08 04:30 UTC = 2026-07-07 22:30 America/Mexico_City (UTC-6):
        // the tenant-local calendar day is STILL July 7th — the due date has
        // not yet fully elapsed in the tenant's own timezone, even though a
        // bare UTC "today" would already read July 8th (past the due date).
        Carbon::setTestNow(Carbon::parse('2026-07-08 04:30:00', 'UTC'));

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId): void {
            $this->assertSame(ReservationStatus::CONFIRMED, AmenityReservation::findOrFail($reservationId)->reservation_status_id);
        });

        // 2026-07-08 07:00 UTC = 2026-07-08 01:00 America/Mexico_City: now the
        // tenant-local calendar day IS July 8th — the due date has elapsed.
        Carbon::setTestNow(Carbon::parse('2026-07-08 07:00:00', 'UTC'));

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId): void {
            $this->assertSame(ReservationStatus::CANCELLED, AmenityReservation::findOrFail($reservationId)->reservation_status_id);
        });
    }

    // =========================================================================
    // (I) A1 — pending-validation earmark payment grace + resumption after reject
    // =========================================================================

    public function test_pending_validation_payment_holds_off_expiry_past_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);

        [$unit, $account, $reservationId] = $this->inTenant($tenant, function () use ($resident): array {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);
            $account = $this->makeBankAccount('Cuenta amenidad', primary: false);

            $amenity = $this->amenity(['has_cost' => true, 'cost_amount' => 50000, 'bank_account_id' => $account->id]);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
                'holds_slot' => true,
                'cost_amount' => 50000,
                'amount_outstanding' => 50000,
                // Already past — the cron's prefilter would normally select it.
                'payment_deadline' => Carbon::parse('2026-07-08 12:00:00', 'UTC'),
            ]);

            return [$unit, $account, $reservation->id];
        });

        $residentToken = $resident->createToken('resident', ['create.payment_submission'])->plainTextToken;

        // Submitted but NEVER validated — stays PENDING_VALIDATION.
        $this->tenantRequest($residentToken, $tenant)
            ->post('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-07-10',
                'total_amount' => 20000,
                // TRANSFER, not CASH (Slice C): these are all resident self-submissions
                // via payment/submit — cash is admin-register-only. Payment method is
                // incidental to what this test suite actually exercises (expiry timing).
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $account->id,
                'earmark_type' => 'amenity_reservation',
                'earmark_id' => $reservationId,
                // Any non-cash method REQUIRES a comprobante at the controller
                // (pre-existing §8.7.1 r5 gate, unrelated to Slice C).
                'receipt' => $this->fakeReceipt(),
            ])
            ->assertStatus(201);

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId): void {
            $reservation = AmenityReservation::findOrFail($reservationId);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $reservation->reservation_status_id);
            $this->assertTrue((bool) $reservation->holds_slot);
        });
    }

    public function test_reservation_becomes_expirable_again_after_payment_rejection(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $treasurer = $this->makeUser($tenant);

        [$unit, $account, $reservationId] = $this->inTenant($tenant, function () use ($resident): array {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);
            $account = $this->makeBankAccount('Cuenta amenidad', primary: false);

            $amenity = $this->amenity(['has_cost' => true, 'cost_amount' => 50000, 'bank_account_id' => $account->id]);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
                'holds_slot' => true,
                'cost_amount' => 50000,
                'amount_outstanding' => 50000,
                'payment_deadline' => Carbon::parse('2026-07-08 12:00:00', 'UTC'),
            ]);

            return [$unit, $account, $reservation->id];
        });

        $residentToken = $resident->createToken('resident', ['create.payment_submission'])->plainTextToken;
        $treasurerToken = $treasurer->createToken('treasurer', ['update.payment'])->plainTextToken;

        $paymentId = $this->tenantRequest($residentToken, $tenant)
            ->post('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-07-10',
                'total_amount' => 20000,
                // TRANSFER, not CASH (Slice C): these are all resident self-submissions
                // via payment/submit — cash is admin-register-only. Payment method is
                // incidental to what this test suite actually exercises (expiry timing).
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $account->id,
                'earmark_type' => 'amenity_reservation',
                'earmark_id' => $reservationId,
                // Any non-cash method REQUIRES a comprobante at the controller
                // (pre-existing §8.7.1 r5 gate, unrelated to Slice C).
                'receipt' => $this->fakeReceipt(),
            ])
            ->assertStatus(201)
            ->json('data.id');

        // Grace still holds it off, past its original deadline.
        Artisan::call('amenities:cancel-expired-reservations');
        $this->inTenant($tenant, function () use ($reservationId): void {
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, AmenityReservation::findOrFail($reservationId)->reservation_status_id);
        });

        $this->tenantRequest($treasurerToken, $tenant)
            ->postJson("/api/payment/{$paymentId}/reject", ['reason' => 'Comprobante ilegible.'])
            ->assertStatus(200);

        // The rejection re-armed payment_deadline to 2026-07-11 12:00 UTC
        // (now-at-rejection + 1 day). Advance past THAT so the next cron run
        // finally expires it.
        Carbon::setTestNow(Carbon::parse('2026-07-12 00:00:00', 'UTC'));

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId): void {
            $reservation = AmenityReservation::findOrFail($reservationId);
            $this->assertSame(ReservationStatus::CANCELLED, $reservation->reservation_status_id);
            $this->assertFalse((bool) $reservation->holds_slot);
        });
    }

    /**
     * A1's PENDING_VALIDATION guard on the CONFIRMED branch of isExpirable():
     * mirrors test_pending_validation_payment_holds_off_expiry_past_deadline
     * above, but for a CONFIRMED reservation with an outstanding balance past
     * its balance_due_date (rather than PENDING_PAYMENT past its
     * payment_deadline) — the OTHER branch guarded by the same early return.
     */
    public function test_pending_validation_settling_payment_holds_off_expiry_of_a_confirmed_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);

        [$unit, $account, $reservationId] = $this->inTenant($tenant, function () use ($resident): array {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);
            $account = $this->makeBankAccount('Cuenta amenidad', primary: false);

            $amenity = $this->amenity([
                'has_cost' => true,
                'cost_amount' => 100000,
                'requires_deposit' => true,
                'deposit_amount' => 30000,
                'deposit_due_days' => 5,
                'bank_account_id' => $account->id,
            ]);

            $reservation = AmenityReservation::factory()->create([
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => 100000,
                'deposit_amount' => 30000,
                'amount_outstanding' => 70000,
                // Already past — the cron's prefilter would normally select it.
                'balance_due_date' => '2026-06-01',
            ]);

            return [$unit, $account, $reservation->id];
        });

        $residentToken = $resident->createToken('resident', ['create.payment_submission'])->plainTextToken;

        // Submitted the settling payment but NEVER validated — stays PENDING_VALIDATION.
        $this->tenantRequest($residentToken, $tenant)
            ->post('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-07-10',
                'total_amount' => 70000,
                // TRANSFER, not CASH (Slice C): these are all resident self-submissions
                // via payment/submit — cash is admin-register-only. Payment method is
                // incidental to what this test suite actually exercises (expiry timing).
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $account->id,
                'earmark_type' => 'amenity_reservation',
                'earmark_id' => $reservationId,
                // Any non-cash method REQUIRES a comprobante at the controller
                // (pre-existing §8.7.1 r5 gate, unrelated to Slice C).
                'receipt' => $this->fakeReceipt(),
            ])
            ->assertStatus(201);

        Artisan::call('amenities:cancel-expired-reservations');

        $this->inTenant($tenant, function () use ($reservationId): void {
            $reservation = AmenityReservation::findOrFail($reservationId);
            $this->assertSame(ReservationStatus::CONFIRMED, $reservation->reservation_status_id);
            $this->assertTrue((bool) $reservation->holds_slot);
        });
    }

    /**
     * extendDeadlineAfterPaymentRejection()'s CONFIRMED branch — a payment
     * rejection against the SETTLING payment of an already-CONFIRMED
     * reservation (deposit already paid, balance still owing) extends
     * balance_due_date, a plain DATE compared as a date string — a
     * different code path than the PENDING_PAYMENT/payment_deadline branch
     * covered by test_payment_rejection_extends_payment_deadline_by_one_day.
     */
    public function test_payment_rejection_extends_balance_due_date_for_a_confirmed_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $treasurer = $this->makeUser($tenant);

        [$unit, $account, $pastDueId, $futureDueId] = $this->inTenant($tenant, function () use ($resident): array {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);
            $account = $this->makeBankAccount('Cuenta amenidad', primary: false);

            $amenityA = $this->amenity([
                'has_cost' => true,
                'cost_amount' => 100000,
                'requires_deposit' => true,
                'deposit_amount' => 30000,
                'deposit_due_days' => 5,
                'bank_account_id' => $account->id,
            ]);
            $pastDue = AmenityReservation::factory()->create([
                'amenity_id' => $amenityA->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => 100000,
                'deposit_amount' => 30000,
                'amount_outstanding' => 70000,
                // Already past "now" — a rejection must PUSH this forward.
                'balance_due_date' => '2026-07-08',
            ]);

            $amenityB = $this->amenity([
                'has_cost' => true,
                'cost_amount' => 100000,
                'requires_deposit' => true,
                'deposit_amount' => 30000,
                'deposit_due_days' => 5,
                'bank_account_id' => $account->id,
            ]);
            $futureDue = AmenityReservation::factory()->create([
                'amenity_id' => $amenityB->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'holds_slot' => true,
                'cost_amount' => 100000,
                'deposit_amount' => 30000,
                'amount_outstanding' => 70000,
                // Already further out than the grace window — must NOT shorten.
                'balance_due_date' => '2026-08-01',
            ]);

            return [$unit, $account, $pastDue->id, $futureDue->id];
        });

        $residentToken = $resident->createToken('resident', ['create.payment_submission'])->plainTextToken;
        $treasurerToken = $treasurer->createToken('treasurer', ['update.payment'])->plainTextToken;

        $submit = fn (string $reservationId) => $this->tenantRequest($residentToken, $tenant)
            ->post('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-07-10',
                'total_amount' => 70000,
                // TRANSFER, not CASH (Slice C): these are all resident self-submissions
                // via payment/submit — cash is admin-register-only. Payment method is
                // incidental to what this test suite actually exercises (expiry timing).
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $account->id,
                'earmark_type' => 'amenity_reservation',
                'earmark_id' => $reservationId,
                // Any non-cash method REQUIRES a comprobante at the controller
                // (pre-existing §8.7.1 r5 gate, unrelated to Slice C).
                'receipt' => $this->fakeReceipt(),
            ])
            ->assertStatus(201)
            ->json('data.id');

        $pastPaymentId = $submit($pastDueId);
        $futurePaymentId = $submit($futureDueId);

        $reject = fn (string $paymentId) => $this->tenantRequest($treasurerToken, $tenant)
            ->postJson("/api/payment/{$paymentId}/reject", ['reason' => 'Comprobante ilegible.'])
            ->assertStatus(200);

        $reject($pastPaymentId);
        $reject($futurePaymentId);

        $this->inTenant($tenant, function () use ($pastDueId, $futureDueId): void {
            $past = AmenityReservation::findOrFail($pastDueId);
            $this->assertSame('2026-07-11', $past->balance_due_date->toDateString());

            $future = AmenityReservation::findOrFail($futureDueId);
            $this->assertSame('2026-08-01', $future->balance_due_date->toDateString());
        });
    }

    public function test_payment_rejection_extends_payment_deadline_by_one_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'UTC'));

        $tenant = $this->tenantWithFlagEnabled();
        $resident = $this->makeUser($tenant);
        $treasurer = $this->makeUser($tenant);

        [$unit, $account, $pastDeadlineId, $futureDeadlineId] = $this->inTenant($tenant, function () use ($resident): array {
            $unit = $this->unit();
            $this->attachResident($unit, $resident);
            $account = $this->makeBankAccount('Cuenta amenidad', primary: false);

            $amenityA = $this->amenity(['has_cost' => true, 'cost_amount' => 50000, 'bank_account_id' => $account->id]);
            $pastDeadline = AmenityReservation::factory()->create([
                'amenity_id' => $amenityA->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
                'holds_slot' => true,
                'cost_amount' => 50000,
                'amount_outstanding' => 50000,
                // Already past "now" — a rejection must PUSH this forward.
                'payment_deadline' => Carbon::parse('2026-07-08 12:00:00', 'UTC'),
            ]);

            $amenityB = $this->amenity(['has_cost' => true, 'cost_amount' => 50000, 'bank_account_id' => $account->id]);
            $futureDeadline = AmenityReservation::factory()->create([
                'amenity_id' => $amenityB->id,
                'unit_id' => $unit->id,
                'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
                'holds_slot' => true,
                'cost_amount' => 50000,
                'amount_outstanding' => 50000,
                // Already further out than the grace window — must NOT shorten.
                'payment_deadline' => Carbon::parse('2026-08-01 12:00:00', 'UTC'),
            ]);

            return [$unit, $account, $pastDeadline->id, $futureDeadline->id];
        });

        $residentToken = $resident->createToken('resident', ['create.payment_submission'])->plainTextToken;
        $treasurerToken = $treasurer->createToken('treasurer', ['update.payment'])->plainTextToken;

        $submit = fn (string $reservationId) => $this->tenantRequest($residentToken, $tenant)
            ->post('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-07-10',
                'total_amount' => 20000,
                // TRANSFER, not CASH (Slice C): these are all resident self-submissions
                // via payment/submit — cash is admin-register-only. Payment method is
                // incidental to what this test suite actually exercises (expiry timing).
                'payment_method_id' => PaymentMethod::TRANSFER,
                'bank_account_id' => $account->id,
                'earmark_type' => 'amenity_reservation',
                'earmark_id' => $reservationId,
                // Any non-cash method REQUIRES a comprobante at the controller
                // (pre-existing §8.7.1 r5 gate, unrelated to Slice C).
                'receipt' => $this->fakeReceipt(),
            ])
            ->assertStatus(201)
            ->json('data.id');

        $pastPaymentId = $submit($pastDeadlineId);
        $futurePaymentId = $submit($futureDeadlineId);

        $reject = fn (string $paymentId) => $this->tenantRequest($treasurerToken, $tenant)
            ->postJson("/api/payment/{$paymentId}/reject", ['reason' => 'Comprobante ilegible.'])
            ->assertStatus(200);

        $reject($pastPaymentId);
        $reject($futurePaymentId);

        $this->inTenant($tenant, function () use ($pastDeadlineId, $futureDeadlineId): void {
            $past = AmenityReservation::findOrFail($pastDeadlineId);
            $this->assertTrue($past->payment_deadline->eq(Carbon::parse('2026-07-11 12:00:00', 'UTC')));

            $future = AmenityReservation::findOrFail($futureDeadlineId);
            $this->assertTrue($future->payment_deadline->eq(Carbon::parse('2026-08-01 12:00:00', 'UTC')));
        });
    }
}
