<?php

declare(strict_types=1);

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Models\ReservationSurcharge;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\PaymentMethod;
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
 * S9 — surcharges (incident charges): AmenitySurchargeService creates a real
 * OPEN Collections charge on the reservation's unit for an admin-imposed
 * incident, linked via reservation_surcharge. CRITICAL invariant under test:
 * the created charge NEVER carries amenity_reservation_id — it must never be
 * mistaken for the reservation's own cost/deposit balance
 * (AmenityReservationChargeApplicationObserver ignores it entirely).
 */
final class AmenityReservationSurchargeTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

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
    // Happy path: creates a real OPEN charge, linked, WITHOUT the reservation FK
    // =========================================================================

    public function test_admin_creates_a_surcharge_and_it_opens_a_real_debt_on_the_unit(): void
    {
        $scn = $this->scenario();

        $response = $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 25000,
                'description' => 'Vidrio roto del área de asadores.',
            ]);

        $response->assertStatus(201);
        $chargeId = $response->json('data.charge_id');
        $this->assertNotEmpty($chargeId);

        $scn['tenant']->run(function () use ($scn, $chargeId): void {
            $charge = Charge::findOrFail($chargeId);
            $this->assertSame($scn['unit']->id, $charge->unit_id);
            $this->assertSame(25000, (int) $charge->getAttributes()['expected_amount']);
            $this->assertSame(0, (int) $charge->getAttributes()['paid_amount']);
            $this->assertFalse((bool) $charge->getAttributes()['is_cancelled']);
            $this->assertNull($charge->charge_batch_id);
            $this->assertNull($charge->charge_policy_id);

            // CRITICAL invariant: the surcharge charge must NEVER carry
            // amenity_reservation_id — that column is reserved for the
            // reservation's OWN cost/deposit charges (S8).
            $this->assertNull($charge->getAttributes()['amenity_reservation_id']);

            // It is a real OPEN debt — a non-null, non-completed status —
            // and it drives the unit's worst-wins aggregate.
            $this->assertNotSame(ChargeStatus::COMPLETED, $charge->charge_status_id);

            $unit = Unit::findOrFail($scn['unit']->id);
            $this->assertNotNull($unit->charge_status_id);

            $link = ReservationSurcharge::where('reservation_id', $scn['reservationId'])->firstOrFail();
            $this->assertSame($chargeId, $link->charge_id);
            $this->assertSame($scn['conceptId'], $link->charge_concept_id);
            $this->assertSame(25000, (int) $link->getAttributes()['amount']);
            $this->assertSame('Vidrio roto del área de asadores.', $link->description);
            $this->assertNotNull($link->created_by);
        });
    }

    // =========================================================================
    // CRITICAL: must NOT touch the reservation's own balance/status
    // =========================================================================

    public function test_surcharge_does_not_change_the_reservations_amount_outstanding_or_status(): void
    {
        $scn = $this->scenario();

        $before = $scn['tenant']->run(fn () => AmenityReservation::findOrFail($scn['reservationId']));
        $outstandingBefore = (int) $before->getAttributes()['amount_outstanding'];
        $statusBefore = $before->reservation_status_id;

        $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 15000,
            ])
            ->assertStatus(201);

        $scn['tenant']->run(function () use ($scn, $outstandingBefore, $statusBefore): void {
            $after = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame($outstandingBefore, (int) $after->getAttributes()['amount_outstanding']);
            $this->assertSame($statusBefore, $after->reservation_status_id);
        });
    }

    public function test_a_completed_reservation_may_also_receive_a_surcharge(): void
    {
        $scn = $this->scenario();

        $scn['tenant']->run(function () use ($scn): void {
            AmenityReservation::where('id', $scn['reservationId'])->update([
                'reservation_status_id' => ReservationStatus::COMPLETED,
            ]);
        });

        $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 10000,
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_resident_without_manage_permission_is_forbidden(): void
    {
        $scn = $this->scenario();

        $response = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 10000,
            ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // FSM gate: only CONFIRMED or COMPLETED reservations
    // =========================================================================

    public function test_surcharge_rejected_when_reservation_is_pending_payment(): void
    {
        $scn = $this->scenario();

        $scn['tenant']->run(function () use ($scn): void {
            AmenityReservation::where('id', $scn['reservationId'])->update([
                'reservation_status_id' => ReservationStatus::PENDING_PAYMENT,
            ]);
        });

        $response = $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 10000,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('confirmada o completada', (string) $response->json('message'));
    }

    public function test_surcharge_rejected_when_reservation_is_pending_approval(): void
    {
        $scn = $this->scenario();

        $scn['tenant']->run(function () use ($scn): void {
            AmenityReservation::where('id', $scn['reservationId'])->update([
                'reservation_status_id' => ReservationStatus::PENDING_APPROVAL,
            ]);
        });

        $response = $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 10000,
            ]);

        $response->assertStatus(422);
    }

    public function test_surcharge_rejected_when_reservation_is_cancelled(): void
    {
        $scn = $this->scenario();

        $scn['tenant']->run(function () use ($scn): void {
            AmenityReservation::where('id', $scn['reservationId'])->update([
                'reservation_status_id' => ReservationStatus::CANCELLED,
                'holds_slot' => false,
            ]);
        });

        $response = $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 10000,
            ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // Validation
    // =========================================================================

    public function test_surcharge_rejected_for_nonexistent_charge_concept(): void
    {
        $scn = $this->scenario();

        $response = $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => (string) Str::uuid(),
                'amount' => 10000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('charge_concept_id');
    }

    public function test_surcharge_rejected_for_a_cross_tenant_charge_concept(): void
    {
        $scnA = $this->scenario();
        $scnB = $this->scenario();

        $response = $this->tenantRequest($scnA['boardToken'], $scnA['tenant'])
            ->postJson("/api/amenity_reservation/{$scnA['reservationId']}/surcharges", [
                'charge_concept_id' => $scnB['conceptId'],
                'amount' => 10000,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('charge_concept_id');
    }

    public function test_surcharge_rejected_for_amount_below_minimum(): void
    {
        $scn = $this->scenario();

        $response = $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 0,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amount');
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_cross_tenant_reservation_id_is_not_found(): void
    {
        $scnA = $this->scenario();
        $scnB = $this->scenario();

        $response = $this->tenantRequest($scnA['boardToken'], $scnA['tenant'])
            ->postJson("/api/amenity_reservation/{$scnB['reservationId']}/surcharges", [
                'charge_concept_id' => $scnA['conceptId'],
                'amount' => 10000,
            ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // Collections regression sanity: it is a NORMAL charge — FIFO can pay it
    // =========================================================================

    public function test_a_plain_fifo_payment_can_pay_off_the_surcharge_charge(): void
    {
        $scn = $this->scenario();

        $chargeId = $this->tenantRequest($scn['boardToken'], $scn['tenant'])
            ->postJson("/api/amenity_reservation/{$scn['reservationId']}/surcharges", [
                'charge_concept_id' => $scn['conceptId'],
                'amount' => 20000,
            ])
            ->assertStatus(201)
            ->json('data.charge_id');

        $paymentId = $this->tenantRequest($scn['residentToken'], $scn['tenant'])
            ->postJson('/api/payment/submit', [
                'unit_id' => $scn['unit']->id,
                'paid_at' => now()->toDateString(),
                'total_amount' => 20000,
                'payment_method_id' => PaymentMethod::CASH,
                'bank_account_id' => $scn['account']->id,
            ])
            ->assertStatus(201)
            ->json('data.id');

        $this->tenantRequest($scn['treasurerToken'], $scn['tenant'])
            ->postJson("/api/payment/{$paymentId}/validate")
            ->assertStatus(200);

        $scn['tenant']->run(function () use ($scn, $chargeId): void {
            $charge = Charge::findOrFail($chargeId);
            $this->assertSame(20000, (int) $charge->getAttributes()['paid_amount']);
            $this->assertSame(ChargeStatus::COMPLETED, $charge->charge_status_id);

            // The reservation itself remains untouched by this ordinary payment.
            $reservation = AmenityReservation::findOrFail($scn['reservationId']);
            $this->assertSame(ReservationStatus::CONFIRMED, $reservation->reservation_status_id);
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A tenant with a resident-owned unit, a free (no-cost) amenity, a
     * CONFIRMED reservation, an active tenant charge_concept, and board/
     * resident/treasurer tokens.
     *
     * @return array{tenant: Tenant, unit: Unit, residentToken: string,
     *     treasurerToken: string, boardToken: string, account: BankAccount,
     *     reservationId: string, conceptId: string}
     */
    private function scenario(): array
    {
        $tenant = $this->makeTenant('AmenitySurcharge');
        $this->seedTenant($tenant);
        $tenant->run(function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachToUnit($tenant, $unit, $resident);

        $account = $this->makeBankAccount($tenant, 'Caja general');
        $treasurer = $this->makeUser($tenant);
        $board = $this->makeUser($tenant);

        [$reservationId, $conceptId] = $tenant->run(function () use ($unit): array {
            $amenity = Amenity::create([
                'name' => 'Amenidad '.uniqid(),
                'capacity' => 10,
                'available_weekdays' => [0, 1, 2, 3, 4, 5, 6],
                'reservation_mode_id' => ReservationMode::BY_DAY,
                'has_cost' => false,
            ]);

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

        return [
            'tenant' => $tenant,
            'unit' => $unit,
            'residentToken' => $resident->createToken('resident', ['create.payment_submission'])->plainTextToken,
            'treasurerToken' => $treasurer->createToken('treasurer', ['update.payment'])->plainTextToken,
            'boardToken' => $board->createToken('board', ['update.amenity_reservation'])->plainTextToken,
            'account' => $account,
            'reservationId' => $reservationId,
            'conceptId' => $conceptId,
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

    private function makeBankAccount(Tenant $tenant, string $name): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account, $name): void {
            $account = BankAccount::create([
                'name' => $name,
                'bank_account_type_id' => BankAccountType::CASH,
                'bank_account_purpose_id' => Catalog::id(BankAccountPurpose::class, 'Operativa'),
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }
}
