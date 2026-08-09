<?php

namespace Modules\AmenityReservations\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AmenityReservations\Models\Amenity;
use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * S7 — reservation FSM TRANSITIONS ONLY (approve/reject/cancel/complete/
 * no_show). Creation (S6) and earmark payment wiring (S8) are out of scope.
 *
 * Follows the project's tenant-test convention (mirrors
 * AmenityReservationCreationTest / ChargeBatchSummaryTest): model setup
 * happens INSIDE $tenant->run()/inTenant(), HTTP requests happen OUTSIDE via
 * tenantRequest() — tenantRequest() ends any active tenancy before issuing
 * the request, so it must never be nested inside a run() block.
 */
final class AmenityReservationTransitionsTest extends TestCase
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

    private function tenantWithFlagEnabled(string $name = 'Cluster'): Tenant
    {
        $tenant = $this->makeTenant($name.' '.uniqid());
        $this->seedTenant($tenant);
        $this->inTenant($tenant, function (): void {
            Setting::where('slug', AmenityReservationsSettings::ENABLED)->update(['value' => 'true']);
        });

        return $tenant;
    }

    /**
     * A resident-shaped token: exactly the abilities RolePermissionTableSeeder
     * grants the resident role for this domain (no update.amenity_reservation).
     *
     * @return array{0: User, 1: string}
     */
    private function residentToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('resident', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
    }

    /**
     * A board/admin-shaped token: holds update.amenity_reservation (the
     * $amenities writeNoDelete grant).
     *
     * @return array{0: User, 1: string}
     */
    private function boardToken(Tenant $tenant): array
    {
        $user = $this->makeUser($tenant);
        $token = $user->createToken('board', [
            'read.amenity', 'read.amenity_reservation', 'create.amenity_reservation', 'update.amenity_reservation',
        ])->plainTextToken;

        return [$user, $token];
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

    /**
     * Creates a reservation row directly (bypassing ReservationService::create,
     * which is S6's concern) so each transition test starts from a controlled,
     * arbitrary initial status.
     */
    private function reservation(Amenity $amenity, Unit $unit, string $statusId, array $overrides = []): AmenityReservation
    {
        return AmenityReservation::factory()->create(array_merge([
            'amenity_id' => $amenity->id,
            'unit_id' => $unit->id,
            'reservation_status_id' => $statusId,
            'holds_slot' => true,
        ], $overrides));
    }

    // =========================================================================
    // approve()
    // =========================================================================

    public function test_approve_moves_a_free_pending_approval_reservation_to_confirmed(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity(['requires_approval' => true]);
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::PENDING_APPROVAL)->id;
        });

        $response = $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/approve")
            ->assertStatus(200);

        $response->assertJsonPath('data.reservation_status_id', ReservationStatus::CONFIRMED);
        $this->assertNotNull($response->json('data.approved_at'));
        $this->assertNotNull($response->json('data.approved_by'));
    }

    public function test_approve_moves_a_costed_pending_approval_reservation_to_pending_payment_with_deadline(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity(['requires_approval' => true, 'has_cost' => true, 'cost_amount' => 50000, 'deposit_due_days' => 5]);
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::PENDING_APPROVAL, ['cost_amount' => 50000, 'amount_outstanding' => 50000])->id;
        });

        $before = now();

        $response = $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/approve")
            ->assertStatus(200);

        $response->assertJsonPath('data.reservation_status_id', ReservationStatus::PENDING_PAYMENT);
        $this->assertNotNull($response->json('data.approved_at'));
        $this->assertNotNull($response->json('data.approved_by'));

        $deadline = Carbon::parse($response->json('data.payment_deadline'));
        $this->assertTrue($deadline->greaterThan($before));
        $this->assertTrue($deadline->lessThanOrEqualTo($before->copy()->addDays(6)));
    }

    public function test_approve_is_forbidden_when_the_reservation_is_not_pending_approval(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/approve")
            ->assertStatus(422);
    }

    public function test_approve_is_forbidden_for_a_resident_without_manage_ability(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $residentToken] = $this->residentToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity(['requires_approval' => true]);
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::PENDING_APPROVAL)->id;
        });

        $this->tenantRequest($residentToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/approve")
            ->assertStatus(403);
    }

    // =========================================================================
    // reject()
    // =========================================================================

    public function test_reject_moves_a_pending_approval_reservation_to_rejected_and_frees_the_slot(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity(['requires_approval' => true]);
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::PENDING_APPROVAL)->id;
        });

        $response = $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/reject", [
                'rejected_reason' => 'No cumple con el reglamento.',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('data.reservation_status_id', ReservationStatus::REJECTED);
        $response->assertJsonPath('data.holds_slot', false);
        $response->assertJsonPath('data.rejected_reason', 'No cumple con el reglamento.');
    }

    public function test_reject_requires_a_reason(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity(['requires_approval' => true]);
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::PENDING_APPROVAL)->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/reject", [])
            ->assertStatus(422);
    }

    public function test_reject_is_forbidden_when_the_reservation_is_not_pending_approval(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/reject", [
                'rejected_reason' => 'No aplica.',
            ])
            ->assertStatus(422);
    }

    // =========================================================================
    // cancel()
    // =========================================================================

    public function test_board_can_cancel_any_active_reservation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $response = $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/cancel", [
                'cancellation_reason' => 'El residente lo solicitó por teléfono.',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('data.reservation_status_id', ReservationStatus::CANCELLED);
        $response->assertJsonPath('data.holds_slot', false);
        $response->assertJsonPath('data.cancellation_reason', 'El residente lo solicitó por teléfono.');
        $this->assertNotNull($response->json('data.cancelled_at'));
        $this->assertNotNull($response->json('data.cancelled_by'));
    }

    public function test_resident_can_cancel_their_own_reservation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $residentToken] = $this->residentToken($tenant);

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($residentToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/cancel", [
                'cancellation_reason' => 'Ya no puedo asistir.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.reservation_status_id', ReservationStatus::CANCELLED);
    }

    public function test_resident_cannot_cancel_someone_elses_reservation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $residentToken] = $this->residentToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $foreignUnit = $this->unit(); // NOT attached to the requesting resident

            return $this->reservation($amenity, $foreignUnit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($residentToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/cancel", [
                'cancellation_reason' => 'Intento no autorizado.',
            ])
            ->assertStatus(403);
    }

    public function test_cancel_is_forbidden_for_an_already_completed_reservation(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::COMPLETED)->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/cancel", [
                'cancellation_reason' => 'No debería aplicar.',
            ])
            ->assertStatus(422);
    }

    /**
     * Concurrency guard (locked recheck): a real parallel race can't be driven
     * from a single synchronous PHPUnit process/connection, so this exercises
     * the SAME code path the row lock protects — the second call must observe
     * the reservation already cancelled by the first and fail the status
     * check, exactly as it would if two transactions raced for the lock.
     */
    public function test_two_sequential_cancel_calls_the_second_sees_already_cancelled_and_errors(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/cancel", [
                'cancellation_reason' => 'Primera cancelación.',
            ])
            ->assertStatus(200);

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/cancel", [
                'cancellation_reason' => 'Segunda cancelación (debe fallar).',
            ])
            ->assertStatus(422);
    }

    // =========================================================================
    // complete() / no_show()
    // =========================================================================

    public function test_complete_moves_a_confirmed_reservation_to_completed(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/complete")
            ->assertStatus(200)
            ->assertJsonPath('data.reservation_status_id', ReservationStatus::COMPLETED);
    }

    public function test_complete_is_forbidden_when_the_reservation_is_not_confirmed(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity(['requires_approval' => true]);
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::PENDING_APPROVAL)->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/complete")
            ->assertStatus(422);
    }

    public function test_complete_is_forbidden_for_a_resident_without_manage_ability(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [$resident, $residentToken] = $this->residentToken($tenant);

        $reservationId = $this->inTenant($tenant, function () use ($resident): string {
            $amenity = $this->amenity();
            $unit = $this->unit();
            $this->attachResident($unit, $resident);

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($residentToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/complete")
            ->assertStatus(403);
    }

    public function test_no_show_moves_a_confirmed_reservation_to_no_show(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/no-show")
            ->assertStatus(200)
            ->assertJsonPath('data.reservation_status_id', ReservationStatus::NO_SHOW);
    }

    public function test_no_show_is_forbidden_when_the_reservation_is_not_confirmed(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $boardToken] = $this->boardToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CANCELLED, ['holds_slot' => false])->id;
        });

        $this->tenantRequest($boardToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/no-show")
            ->assertStatus(422);
    }

    public function test_no_show_is_forbidden_for_a_resident_without_manage_ability(): void
    {
        $tenant = $this->tenantWithFlagEnabled();
        [, $residentToken] = $this->residentToken($tenant);

        $reservationId = $this->inTenant($tenant, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        $this->tenantRequest($residentToken, $tenant)
            ->postJson("/api/amenity_reservation/{$reservationId}/no-show")
            ->assertStatus(403);
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_transitions_are_isolated_per_tenant(): void
    {
        $tenantA = $this->tenantWithFlagEnabled('Tenant A');
        $tenantB = $this->tenantWithFlagEnabled('Tenant B');

        $reservationIdA = $this->inTenant($tenantA, function (): string {
            $amenity = $this->amenity();
            $unit = $this->unit();

            return $this->reservation($amenity, $unit, ReservationStatus::CONFIRMED)->id;
        });

        [, $boardTokenB] = $this->boardToken($tenantB);

        // Tenant B's board cannot even resolve Tenant A's reservation: the
        // model is TenantScope-scoped, so findOrFail() 404s before any
        // status/authorization check runs.
        $this->tenantRequest($boardTokenB, $tenantB)
            ->postJson("/api/amenity_reservation/{$reservationIdA}/cancel", [
                'cancellation_reason' => 'Cross-tenant attempt.',
            ])
            ->assertStatus(404);
    }
}
