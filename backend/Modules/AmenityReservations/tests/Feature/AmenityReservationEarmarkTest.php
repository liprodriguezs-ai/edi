<?php

declare(strict_types=1);

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentApplication;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Services\ChargeStatusService;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S8 — earmark payment integration: AmenityReservationEarmarkHandler,
 * AmenityChargeService (standalone born-paid charge per confirmed earmark
 * payment), AmenityReservationChargeApplicationObserver (recompute-from-sum
 * FSM sync), and ReservationService::reverseConfirmedEarmarkPayments() wired
 * into cancel(). Mirrors the Projects module's PaymentEarmarkTest structure.
 */
final class AmenityReservationEarmarkTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    private const EARMARK_TYPE = 'amenity_reservation';

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Anti-FIFO: an amenity earmark payment funds ONLY its own charge
    // =========================================================================

    public function test_earmark_payment_pays_only_its_own_charge_never_old_debt(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);
        $oldChargeId = $this->makeOldDebtCharge($scn['tenant'], $scn['unit'], 150000);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn, $paymentId, $oldChargeId): void {
            $charge = Charge::where('amenity_reservation_id', $scn['reservationId'])->firstOrFail();
            $this->assertSame($scn['unit']->id, $charge->unit_id);
            $this->assertSame(50000, (int) $charge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id);
            $this->assertNull($charge->charge_batch_id);
            $this->assertNull($charge->charge_policy_id);

            $applications = PaymentApplication::where('payment_id', $paymentId)->get();
            $this->assertCount(1, $applications);
            $this->assertSame($charge->id, $applications->first()->charge_id);
            $this->assertSame(50000, (int) $applications->first()->getAttributes()['applied_amount']);

            // Old debt untouched — the earmark defeats FIFO by design.
            $oldCharge = Charge::findOrFail($oldChargeId);
            $this->assertSame(0, (int) $oldCharge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::OVERDUE, $oldCharge->charge_status_id);

            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(0, (int) $reservation->getAttributes()['amount_outstanding']);
            $this->assertSame(ReservationStatus::CONFIRMED, $reservation->reservation_status_id);

            $this->assertSame(50000, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::CONFIRMED, $payment->payment_status_id);
            $this->assertMatchesRegularExpression('/^R-\d{4}-\d{6}$/', (string) $payment->receipt_number);
        });
    }

    // =========================================================================
    // Overshoot
    // =========================================================================

    public function test_submit_rejects_amount_exceeding_outstanding(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 60000));

        $response->assertStatus(422);
        $this->assertStringContainsString('supera el saldo', (string) $response->json('message'));
    }

    /**
     * The under-lock re-check in resolveApplications(): simulate a competing
     * earmark payment having already reduced amount_outstanding between submit
     * and validate — the exact concurrency window lockForUpdate + re-check
     * protects (same "can't drive a real race from one sync connection, so
     * exercise the identical code path" precedent as the S7 sequential-cancel test).
     */
    public function test_validate_rechecks_overshoot_under_lock_and_rolls_back(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000))
            ->assertStatus(201)
            ->json('data.id');

        $scn['tenant']->run(function () use ($scn): void {
            AmenityReservation::where('id', $scn['reservationId'])->update(['amount_outstanding' => 10000]);
        });

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(422);

        $scn['tenant']->run(function () use ($scn, $paymentId): void {
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::PENDING_VALIDATION, $payment->payment_status_id);
            $this->assertNull($payment->receipt_number);
            $this->assertSame(0, Charge::where('amenity_reservation_id', $scn['reservationId'])->count());
            $this->assertSame(0, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
        });
    }

    // =========================================================================
    // Deposit-only confirmation, then the balance settles
    // =========================================================================

    public function test_deposit_only_payment_confirms_with_remaining_balance_then_second_payment_settles(): void
    {
        $scn = $this->scenario([
            'cost_amount' => 100000,
            'requires_deposit' => true,
            'deposit_amount' => 30000,
            'deposit_due_days' => 5,
        ]);

        $firstPaymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 30000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$firstPaymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn): void {
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::CONFIRMED, $reservation->reservation_status_id);
            $this->assertSame(70000, (int) $reservation->getAttributes()['amount_outstanding']);
            $this->assertNotNull($reservation->balance_due_date);
        });

        $secondPaymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 70000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$secondPaymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn): void {
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(0, (int) $reservation->getAttributes()['amount_outstanding']);
            $this->assertSame(ReservationStatus::CONFIRMED, $reservation->reservation_status_id);
            $this->assertNull($reservation->balance_due_date);
            $this->assertSame(2, Charge::where('amenity_reservation_id', $scn['reservationId'])->count());
        });
    }

    // =========================================================================
    // A3: no-deposit amenity — a token partial must NOT confirm the reservation
    // =========================================================================

    public function test_no_deposit_amenity_token_partial_payment_does_not_confirm(): void
    {
        $scn = $this->scenario(['cost_amount' => 15000]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 1000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn): void {
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $reservation->reservation_status_id);
            $this->assertSame(14000, (int) $reservation->getAttributes()['amount_outstanding']);
        });
    }

    public function test_no_deposit_amenity_confirms_only_once_fully_paid(): void
    {
        $scn = $this->scenario(['cost_amount' => 15000]);

        $firstPaymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 1000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$firstPaymentId}/validate")
            ->assertStatus(200);

        $secondPaymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 14000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$secondPaymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn): void {
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::CONFIRMED, $reservation->reservation_status_id);
            $this->assertSame(0, (int) $reservation->getAttributes()['amount_outstanding']);
        });
    }

    // =========================================================================
    // A2: cancel rejects a pending-validation earmark payment — no orphan
    // =========================================================================

    public function test_cancel_rejects_a_pending_validation_earmark_payment_no_orphan(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 20000))
            ->assertStatus(201)
            ->json('data.id');

        // Left PENDING_VALIDATION deliberately — never validated.

        $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/cancel", [
                'cancellation_reason' => 'El residente lo solicitó por teléfono.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.reservation_status_id', ReservationStatus::CANCELLED);

        $scn['tenant']->run(function () use ($paymentId): void {
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::CANCELLED, $payment->payment_status_id);
            $this->assertNotNull($payment->cancellation_reason);
        });
    }

    /**
     * A2 combined path: a CONFIRMED deposit (already reversible via
     * reverseConfirmedEarmarkPayments()) AND a SEPARATE PENDING_VALIDATION
     * settling payment (rejectable via rejectPendingEarmarkPayments()) both
     * exist on the SAME reservation when it is cancelled. Both cascades run
     * back-to-back inside cancel()'s single transaction — proves neither
     * throws nor leaves an orphan when the other one is present too.
     */
    public function test_cancel_with_a_confirmed_deposit_and_a_separate_pending_settling_payment_reverses_and_rejects_both(): void
    {
        $scn = $this->scenario([
            'cost_amount' => 100000,
            'requires_deposit' => true,
            'deposit_amount' => 30000,
            'deposit_due_days' => 5,
        ]);

        $depositPaymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 30000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$depositPaymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn): void {
            $this->assertSame(30000, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
        });

        $settlingPaymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 40000))
            ->assertStatus(201)
            ->json('data.id');

        // Left PENDING_VALIDATION deliberately — never validated.

        $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/cancel", [
                'cancellation_reason' => 'El residente lo solicitó por teléfono.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.reservation_status_id', ReservationStatus::CANCELLED);

        $scn['tenant']->run(function () use ($scn, $depositPaymentId, $settlingPaymentId): void {
            $deposit = Payment::findOrFail($depositPaymentId);
            $this->assertSame(PaymentStatus::CANCELLED, $deposit->payment_status_id);

            $settling = Payment::findOrFail($settlingPaymentId);
            $this->assertSame(PaymentStatus::CANCELLED, $settling->payment_status_id);
            $this->assertNotNull($settling->cancellation_reason);

            // The confirmed deposit was reversed — bank balance back to zero.
            $this->assertSame(0, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);

            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::CANCELLED, $reservation->reservation_status_id);
        });
    }

    /**
     * A2 concurrency stand-in: the pending-validation payment is ALREADY
     * terminal (rejected directly, e.g. by a treasurer or a raced cron run)
     * BY THE TIME cancel() runs its own rejectPendingEarmarkPayments() sweep.
     * A true concurrent race can't be driven from one synchronous PHPUnit
     * connection, so this proves the lockForUpdate() query correctly
     * EXCLUDES an already-non-PENDING_VALIDATION row (Postgres re-evaluates
     * the WHERE clause against the latest committed row before locking)
     * instead of calling PaymentService::reject() on it and throwing —
     * cancel() completes cleanly and the payment's ORIGINAL rejection reason
     * is left untouched (never overwritten by the reservation's own
     * cancellation_reason).
     */
    public function test_cancel_completes_gracefully_when_the_pending_payment_is_already_terminal(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 20000))
            ->assertStatus(201)
            ->json('data.id');

        // Already terminal BEFORE cancel() runs — the exact state a raced
        // treasurer reject would have left it in.
        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/reject", ['reason' => 'Comprobante duplicado.'])
            ->assertStatus(200);

        $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/cancel", [
                'cancellation_reason' => 'El residente lo solicitó por teléfono.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.reservation_status_id', ReservationStatus::CANCELLED);

        $scn['tenant']->run(function () use ($paymentId): void {
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::CANCELLED, $payment->payment_status_id);
            $this->assertSame('Comprobante duplicado.', $payment->cancellation_reason);
        });
    }

    public function test_first_payment_below_deposit_minimum_is_422(): void
    {
        $scn = $this->scenario([
            'cost_amount' => 100000,
            'requires_deposit' => true,
            'deposit_amount' => 30000,
            'deposit_due_days' => 5,
        ]);

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 10000));

        $response->assertStatus(422);
        $this->assertStringContainsString('depósito', (string) $response->json('message'));
    }

    // =========================================================================
    // Slice C: residents cannot self-report a cash payment; admin capture can
    // =========================================================================

    public function test_resident_submit_with_cash_method_is_rejected(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $payload = $this->submitPayload($scn, 50000);
        $payload['payment_method_id'] = PaymentMethod::CASH;

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Los residentes no pueden pagar en efectivo',
            (string) $response->json('message')
        );
    }

    public function test_admin_register_with_cash_method_is_allowed_and_confirmed(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);
        $adminToken = $this->makeUser($scn['tenant'])->createToken('admin', ['*'])->plainTextToken;

        $paymentId = $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/payment/register', $this->registerPayload($scn, 50000))
            ->assertStatus(201)
            ->json('data.id');

        $scn['tenant']->run(function () use ($paymentId): void {
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentMethod::CASH, $payment->payment_method_id);
            $this->assertSame(PaymentStatus::CONFIRMED, $payment->payment_status_id);
        });
    }

    // =========================================================================
    // GA hardening (S17, integration-reviewer W2): payment/submit and
    // payment/register are SHARED Collections routes, not behind
    // EnsureAmenityReservationsEnabled — the earmark handler itself must
    // reject earmark payments once the tenant flag is disabled.
    // =========================================================================

    public function test_submit_rejects_earmark_payment_when_module_flag_is_disabled(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $scn['tenant']->run(function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'false']);
        });

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000));

        $response->assertStatus(422);
        $this->assertStringContainsString('deshabilitado', (string) $response->json('message'));
    }

    // =========================================================================
    // Destination bank account
    // =========================================================================

    public function test_submit_rejects_when_destination_account_is_not_the_amenitys_account(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        // Absent bank_account_id → PaymentController::submit defaults to the
        // cluster's PRIMARY account, which is deliberately NOT the amenity's account.
        $payload = $this->submitPayload($scn, 50000);
        unset($payload['bank_account_id']);

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('cuenta bancaria', (string) $response->json('message'));

        // Explicit wrong account, via the admin register path (never defaults).
        $adminToken = $this->makeUser($scn['tenant'])->createToken('admin', ['*'])->plainTextToken;

        $response = $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/payment/register', $this->registerPayload($scn, 50000, $scn['otherAccount']->id));

        $response->assertStatus(422);
        $this->assertStringContainsString('cuenta bancaria', (string) $response->json('message'));
    }

    // =========================================================================
    // At most one pending report per reservation
    // =========================================================================

    public function test_submit_rejects_a_second_pending_report_for_the_same_reservation(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 20000))
            ->assertStatus(201);

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 20000));

        $response->assertStatus(422);
        $this->assertStringContainsString('pendiente de validación', (string) $response->json('message'));
    }

    // =========================================================================
    // Tenancy: a foreign tenant's reservation id is not resolvable
    // =========================================================================

    public function test_submit_rejects_a_foreign_tenants_reservation_id(): void
    {
        $scnA = $this->scenario(['cost_amount' => 50000]);
        $scnB = $this->scenario(['cost_amount' => 50000]);

        $payload = $this->submitPayload($scnA, 20000);
        $payload['earmark_id'] = $scnB['reservationId'];

        $response = $this->tenantRequest($scnA['residentToken'], $scnA['tenant'])
            ->post('/api/payment/submit', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('No existe la reservación', (string) $response->json('message'));
    }

    // =========================================================================
    // Non-payable reservation states
    // =========================================================================

    public function test_submit_rejects_a_reservation_that_is_not_in_a_payable_status(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $scn['tenant']->run(function () use ($scn): void {
            AmenityReservation::where('id', $scn['reservationId'])->update([
                'reservation_status_id' => ReservationStatus::CANCELLED,
                'holds_slot' => false,
            ]);
        });

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000));

        $response->assertStatus(422);
        $this->assertStringContainsString('no admite pagos', (string) $response->json('message'));
    }

    // =========================================================================
    // Cancellation reversal
    // =========================================================================

    public function test_cancel_after_partial_deposit_payment_reverses_it(): void
    {
        $scn = $this->scenario([
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

        $scn['tenant']->run(function () use ($scn): void {
            $this->assertSame(30000, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);
        });

        $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/cancel", [
                'cancellation_reason' => 'El residente lo solicitó por teléfono.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.reservation_status_id', ReservationStatus::CANCELLED);

        $scn['tenant']->run(function () use ($scn, $paymentId): void {
            $payment = Payment::findOrFail($paymentId);
            $this->assertSame(PaymentStatus::CANCELLED, $payment->payment_status_id);
            $this->assertSame(0, (int) BankAccount::findOrFail($scn['account']->id)->getAttributes()['current_balance']);

            // FIX (E2E QA — stale amount_outstanding on a terminal reservation):
            // amount_outstanding freezes at its last ACTIVE value (70000 — the
            // 30000 deposit had already settled against the 100000 cost) instead
            // of being resurrected to the full cost. The reservation is CANCELLED
            // (dead) — its earmark payment being reversed must not make it look
            // like a fresh 100000 balance is owed again.
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::CANCELLED, $reservation->reservation_status_id);
            $this->assertSame(70000, (int) $reservation->getAttributes()['amount_outstanding']);
        });
    }

    /**
     * FIX (E2E QA — stale amount_outstanding on a terminal reservation): a
     * FULLY paid reservation (amount_outstanding already 0, CONFIRMED) that
     * gets cancelled must NOT resurrect its frozen cost_amount snapshot as a
     * fresh "Saldo pendiente" once its earmark payments are reversed as part
     * of the cancellation. Before the fix, cancel() reversed the earmark
     * payments BEFORE writing CANCELLED, so the cascading sync
     * (syncFromChargeApplication) saw the reservation as still CONFIRMED and
     * recomputed amount_outstanding back up to the full 100000 cost; the
     * final CANCELLED save never touched amount_outstanding, so that stale
     * value stuck. This is the exact symptom E2E QA reported: a Cancelada
     * reservation showing a stale "Saldo pendiente" + a "Pagar" button.
     */
    public function test_cancel_after_full_payment_does_not_resurrect_outstanding_balance(): void
    {
        $scn = $this->scenario([
            'cost_amount' => 100000,
            'requires_deposit' => true,
            'deposit_amount' => 30000,
            'deposit_due_days' => 5,
        ]);

        $firstPaymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 30000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$firstPaymentId}/validate")
            ->assertStatus(200);

        $secondPaymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 70000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$secondPaymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn): void {
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::CONFIRMED, $reservation->reservation_status_id);
            $this->assertSame(0, (int) $reservation->getAttributes()['amount_outstanding']);
        });

        $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/cancel", [
                'cancellation_reason' => 'El residente lo solicitó por teléfono.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.reservation_status_id', ReservationStatus::CANCELLED);

        $scn['tenant']->run(function () use ($scn): void {
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::CANCELLED, $reservation->reservation_status_id);
            $this->assertSame(0, (int) $reservation->getAttributes()['amount_outstanding']);
        });
    }

    public function test_direct_payment_cancel_reverts_reservation_from_confirmed_to_pending_payment(): void
    {
        $scn = $this->scenario([
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

        $scn['tenant']->run(function () use ($scn): void {
            $this->assertSame(
                ReservationStatus::CONFIRMED,
                AmenityReservation::findOrFail($scn['reservationId'])->reservation_status_id,
            );
        });

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/cancel", ['reason' => 'Error de captura.'])
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn): void {
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $reservation->reservation_status_id);
            $this->assertSame(100000, (int) $reservation->getAttributes()['amount_outstanding']);
            $this->assertNull($reservation->balance_due_date);
        });
    }

    // =========================================================================
    // FIX (reviewer-confirmed HIGH): reversed earmark charge must not stay OPEN
    // =========================================================================

    /**
     * Proves the FIX for the HIGH bug: reversing a partial earmark payment
     * (payment cancel) must cancel the born-paid amenity charge, not leave it
     * open as a FIFO magnet. Before the fix, cancel() deleted the
     * PaymentApplication and zeroed paid_amount but never set is_cancelled,
     * so the charge came back to life as an ordinary open charge — the very
     * next FIFO payment for the unit would grab it (with `due_date` = today,
     * it also aged into the delinquency pipeline).
     */
    public function test_cancelled_earmark_payment_cancels_its_charge_and_stays_out_of_fifo(): void
    {
        $scn = $this->scenario([
            'cost_amount' => 100000,
            'requires_deposit' => true,
            'deposit_amount' => 30000,
            'deposit_due_days' => 5,
        ]);

        // Partial earmark payment: charge #1 born, COMPLETED for its own
        // 30000, reservation CONFIRMED with 70000 still outstanding.
        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 30000))
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $amenityChargeId = $scn['tenant']->run(function () use ($scn): string {
            $charge = Charge::where('amenity_reservation_id', $scn['reservationId'])->firstOrFail();
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id);
            $this->assertFalse((bool) $charge->getAttributes()['is_cancelled']);

            return $charge->id;
        });

        // Reverse the earmark payment directly (treasurer capture error), NOT
        // via the reservation's own cancel().
        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/cancel", ['reason' => 'Error de captura.'])
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn, $amenityChargeId): void {
            $charge = Charge::findOrFail($amenityChargeId);
            $this->assertTrue((bool) $charge->getAttributes()['is_cancelled']);
            $this->assertNotNull($charge->cancellation_reason);
            $this->assertSame(0, (int) $charge->getAttributes()['paid_amount']);

            // The reservation reverted (S8 sync) — the maintenance money below
            // must not silently confirm it or advance its FSM.
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $reservation->reservation_status_id);
        });

        // Now submit + confirm a PLAIN (no-earmark) FIFO payment for the SAME
        // unit — it must NOT be able to apply to the now-cancelled amenity charge.
        $adminToken = $this->makeUser($scn['tenant'])->createToken('admin', ['*'])->plainTextToken;
        $plainAccount = $scn['tenant']->run(fn () => BankAccount::where('is_primary', true)->firstOrFail());

        $plainPaymentId = $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson('/api/payment/register', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-02',
                'total_amount' => 5000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $plainAccount->id,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $scn['tenant']->run(function () use ($scn, $amenityChargeId, $plainPaymentId): void {
            // The cancelled amenity charge received nothing from the plain payment.
            $amenityCharge = Charge::findOrFail($amenityChargeId);
            $this->assertSame(0, (int) $amenityCharge->getAttributes()['paid_amount']);
            $this->assertSame(0, PaymentApplication::where('charge_id', $amenityChargeId)->count());

            // Its payment_application went somewhere else (credit, since there
            // is no other open charge on this unit) — never the amenity charge.
            $plainApplications = PaymentApplication::where('payment_id', $plainPaymentId)->get();
            foreach ($plainApplications as $application) {
                $this->assertNotSame($amenityChargeId, $application->charge_id);
            }

            // The reservation still did not advance from unrelated maintenance money.
            // The earlier payment cancel already reverted its own settlement back to
            // the full cost_amount snapshot (100000) — the plain payment above must
            // not touch this at all.
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $reservation->reservation_status_id);
            $this->assertSame(100000, (int) $reservation->getAttributes()['amount_outstanding']);

            // Cancelled charges are excluded from the daily status-refresh scan
            // (ChargeStatusService::refreshAllOpenCharges filters is_cancelled=false)
            // — it must not resurface as OVERDUE.
            app(ChargeStatusService::class)->refreshAllOpenCharges();
            $amenityCharge->refresh();
            $this->assertNotSame(ChargeStatus::OVERDUE, $amenityCharge->charge_status_id);
        });
    }

    // =========================================================================
    // Additional §7-style gap coverage
    // =========================================================================

    public function test_submit_rejects_when_unit_id_does_not_match_the_reservations_unit(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);
        $otherUnit = $this->makeUnit($scn['tenant']);

        // A resident who belongs to a DIFFERENT unit than the reservation's own:
        // submit()'s own unit-membership gate passes (they DO belong to
        // otherUnit), but the earmark handler's unit-match-to-the-reservation
        // check must still reject it — same tenant, wrong unit.
        $otherResident = $this->makeUser($scn['tenant']);
        $this->attachToUnit($scn['tenant'], $otherUnit, $otherResident);
        $otherResidentToken = $otherResident->createToken('resident2', ['create.payment_submission'])->plainTextToken;

        $payload = $this->submitPayload($scn, 50000);
        $payload['unit_id'] = $otherUnit->id;

        $response = $this->tenantRequest($otherResidentToken, $scn['tenant'])
            ->post('/api/payment/submit', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('no corresponde a la unidad', (string) $response->json('message'));
    }

    /**
     * Pay-after-approval ordering (spec R3): a reservation still awaiting
     * board approval must reject any earmarked payment outright.
     */
    public function test_submit_rejects_earmark_payment_against_a_pending_approval_reservation(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $scn['tenant']->run(function () use ($scn): void {
            AmenityReservation::where('id', $scn['reservationId'])->update([
                'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
            ]);
        });

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 50000));

        $response->assertStatus(422);
        $this->assertStringContainsString('no admite pagos', (string) $response->json('message'));
    }

    /**
     * assertPayable's outstanding <= 0 branch: a CONFIRMED, fully-settled
     * reservation (amount_outstanding = 0) must reject a further earmark
     * payment even though CONFIRMED is otherwise a payable status.
     */
    public function test_submit_rejects_a_confirmed_reservation_with_no_outstanding_balance(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);

        $scn['tenant']->run(function () use ($scn): void {
            AmenityReservation::where('id', $scn['reservationId'])->update([
                'reservation_status_id' => ReservationStatus::CONFIRMED,
                'amount_outstanding' => 0,
            ]);
        });

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', $this->submitPayload($scn, 10000));

        $response->assertStatus(422);
        $this->assertStringContainsString('no tiene saldo pendiente', (string) $response->json('message'));
    }

    // =========================================================================
    // Observer isolation
    // =========================================================================

    public function test_observer_ignores_payment_applications_on_non_amenity_charges(): void
    {
        $scn = $this->scenario(['cost_amount' => 50000]);
        $oldChargeId = $this->makeOldDebtCharge($scn['tenant'], $scn['unit'], 20000);

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->post('/api/payment/submit', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => '2026-07-01',
                'total_amount' => 20000,
                'payment_method_id' => PaymentMethod::CASH,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn, $oldChargeId): void {
            $oldCharge = Charge::findOrFail($oldChargeId);
            $this->assertSame(20000, (int) $oldCharge->getAttributes()['paid_amount']);

            // The reservation never received an earmark payment — untouched.
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::PENDING_PAYMENT, $reservation->reservation_status_id);
            $this->assertSame(50000, (int) $reservation->getAttributes()['amount_outstanding']);
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A tenant with the flag enabled, a resident-owned unit, a costed amenity
     * (bank_account_id = a NON-primary account so absent/mismatched-account
     * submissions are distinguishable from the default), and a PENDING_PAYMENT
     * reservation created DIRECTLY (bypassing ReservationService::create,
     * which is S6/S7's concern) with the S6 money snapshots already in place.
     *
     * @param  array<string, mixed>  $amenityOverrides
     * @return array{tenant: Tenant, unit: Unit, residentToken: string, treasurerToken: string,
     *     boardToken: string, account: BankAccount, otherAccount: BankAccount, reservationId: string}
     */
    private function scenario(array $amenityOverrides = []): array
    {
        $tenant = $this->makeTenant('AmenityEarmark');
        $this->seedTenant($tenant);
        $tenant->run(function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachToUnit($tenant, $unit, $resident);

        $otherAccount = $this->makeBankAccount($tenant, 'Caja general', primary: true);
        $amenityAccount = $this->makeBankAccount($tenant, 'Cuenta amenidad', primary: false);

        $treasurer = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);

        $reservationId = $tenant->run(function () use ($unit, $amenityAccount, $amenityOverrides): string {
            $amenity = Amenity::create(array_merge([
                'name' => 'Amenidad '.uniqid(),
                'capacity' => 10,
                'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
                'reservation_mode_id' => ReservationMode::BY_DAY,
                'has_cost' => true,
                'cost_amount' => 50000,
                'requires_deposit' => false,
                'deposit_amount' => null,
                'deposit_due_days' => null,
                'bank_account_id' => $amenityAccount->id,
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
            'residentToken' => $resident->createToken('resident', ['create.payment_submission'])->plainTextToken,
            'treasurerToken' => $treasurer->createToken('treasurer', ['update.payment'])->plainTextToken,
            'boardToken' => $board->createToken('board', ['update.amenity_reservation', 'update.payment'])->plainTextToken,
            'account' => $amenityAccount,
            'otherAccount' => $otherAccount,
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
            // TRANSFER, not CASH: Slice C rejects a resident self-submitting
            // cash, and the tests above this helper exercise OTHER validation
            // branches — they must not trip the cash guard first.
            'payment_method_id' => PaymentMethod::TRANSFER,
            'bank_account_id' => $scn['account']->id,
            'earmark_type' => self::EARMARK_TYPE,
            'earmark_id' => $scn['reservationId'],
            // Any non-cash method REQUIRES a comprobante at the controller
            // (pre-existing §8.7.1 r5 gate, unrelated to Slice C but now
            // reachable since these tests no longer submit as cash). A REAL
            // fake image (not ::create(), which reports a size/mime without
            // real bytes) — Spatie MediaLibrary sniffs actual file content.
            'receipt' => UploadedFile::fake()->image('comprobante.png'),
        ];
    }

    /**
     * @param  array{unit: Unit, account: BankAccount, reservationId: string}  $scn
     * @return array<string, mixed>
     */
    private function registerPayload(array $scn, int $amount, ?string $bankAccountId = null): array
    {
        return [
            'unit_id' => $scn['unit']->id,
            'paid_at' => '2026-07-01',
            'total_amount' => $amount,
            'payment_method_id' => PaymentMethod::CASH,
            'bank_account_id' => $bankAccountId ?? $scn['account']->id,
            'earmark_type' => self::EARMARK_TYPE,
            'earmark_id' => $scn['reservationId'],
        ];
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(100, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function attachToUnit(Tenant $tenant, Unit $unit, User $user): void
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

    private function makeBankAccount(Tenant $tenant, string $name, bool $primary): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $name, $primary): void {
            $account = BankAccount::create([
                'name' => $name,
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

    /**
     * An old, overdue, unpaid charge — exactly what plain FIFO would grab first.
     */
    private function makeOldDebtCharge(Tenant $tenant, Unit $unit, int $amount): string
    {
        return (string) $tenant->run(function () use ($unit, $amount): string {
            $concept = ChargeConcept::create([
                'code' => 'MAINT_OLD_'.mt_rand(1000, 99999),
                'name' => 'Mantenimiento',
                'charge_concept_category_id' => Catalog::id(ChargeConceptCategory::class, 'Ordinaria'),
                'is_active' => true,
            ]);

            $charge = new Charge;
            $charge->fill([
                'charge_concept_id' => $concept->id,
                'unit_id' => $unit->id,
                'expected_amount' => $amount,
                'issued_at' => '2025-01-01',
                'due_date' => '2025-01-31',
                'grace_days' => 0,
            ]);
            $charge->paid_amount = 0;
            $charge->is_cancelled = false;
            $charge->charge_status_id = app(ChargeStatusService::class)->resolveStatusKey($charge);
            $charge->save();

            return $charge->id;
        });
    }
}
