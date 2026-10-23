<?php

declare(strict_types=1);

namespace Modules\Appointment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Appointment\Models\Appointment;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\Appointment\Models\AppointmentType;
use Modules\Appointment\Tests\Support\WithAppointmentFixtures;
use Modules\Clinic\Models\PractitionerProfile;
use Tests\TestCase;

/**
 * odd/tasks/edi-agenda.md — S2 (server-derived status), S3 (booking), S4 (no double booking).
 */
final class AppointmentBookingTest extends TestCase
{
    use RefreshDatabase;
    use WithAppointmentFixtures;

    public function test_booking_creates_a_scheduled_appointment_with_the_type_default_duration(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $startsAt = $this->clinicTime(1, 10);

        $response = $this->tenantRequest($token, $tenant)->postJson('/api/appointment', [
            'child_id' => $fx['child']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'starts_at' => $startsAt,
            // Forged lifecycle fields must be ignored.
            'appointment_status_id' => AppointmentStatus::FULFILLED,
            'arrived_at' => now()->toIso8601String(),
        ])->assertCreated();

        $response->assertJsonPath('data.appointment_status_id', AppointmentStatus::SCHEDULED)
            ->assertJsonPath('data.arrived_at', null)
            ->assertJsonPath('data.health_center_id', $fx['practitioner']->health_center_id)
            ->assertJsonPath('data.is_walk_in', false);

        $start = Carbon::parse($response->json('data.starts_at'));
        $end = Carbon::parse($response->json('data.ends_at'));
        $this->assertTrue($start->equalTo(Carbon::parse($startsAt)));
        $this->assertSame(
            $this->inTenant($tenant, fn () => AppointmentType::findOrFail(AppointmentType::SCREENING)->default_duration_minutes),
            (int) $start->diffInMinutes($end),
        );
    }

    public function test_explicit_duration_overrides_the_type_default(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());

        $response = $this->tenantRequest($token, $tenant)->postJson('/api/appointment', [
            'child_id' => $fx['child']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'appointment_type_id' => AppointmentType::STIMULATION,
            'starts_at' => $this->clinicTime(1, 10),
            'duration_minutes' => 45,
        ])->assertCreated();

        $this->assertSame(45, (int) Carbon::parse($response->json('data.starts_at'))
            ->diffInMinutes(Carbon::parse($response->json('data.ends_at'))));
    }

    public function test_booking_requires_a_practitioner_and_a_future_start(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());

        $this->tenantRequest($token, $tenant)->postJson('/api/appointment', [
            'child_id' => $fx['child']->id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'starts_at' => $this->clinicTime(1, 10),
        ])->assertStatus(422)->assertJsonValidationErrors('practitioner_id');

        $this->tenantRequest($token, $tenant)->postJson('/api/appointment', [
            'child_id' => $fx['child']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'starts_at' => $this->clinicTime(-1, 10),
        ])->assertStatus(422)->assertJsonValidationErrors('starts_at');
    }

    public function test_overlapping_booking_for_the_same_practitioner_is_rejected(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $other = $this->inTenant($tenant, fn () => PractitionerProfile::factory()->create([
            'health_center_id' => $fx['healthCenter']->id,
        ]));

        $book = fn (string $practitionerId, string $startsAt) => $this->tenantRequest($token, $tenant)
            ->postJson('/api/appointment', [
                'child_id' => $fx['child']->id,
                'practitioner_id' => $practitionerId,
                'appointment_type_id' => AppointmentType::SCREENING,
                'starts_at' => $startsAt,
                'duration_minutes' => 30,
            ]);

        $book($fx['practitioner']->id, $this->clinicTime(1, 10))->assertCreated();

        $book($fx['practitioner']->id, $this->clinicTime(1, 10, 15))
            ->assertStatus(422)
            ->assertJsonValidationErrors('starts_at')
            ->assertJsonPath('errors.starts_at.0', 'El profesional ya tiene una cita en ese horario.');

        // Back-to-back is fine ('[)' range) and another practitioner is free.
        $book($fx['practitioner']->id, $this->clinicTime(1, 10, 30))->assertCreated();
        $book($other->id, $this->clinicTime(1, 10, 15))->assertCreated();
    }

    public function test_cancelled_appointments_free_their_slot(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $this->inTenant($tenant, fn () => $this->makeAppointment(
            $fx['child'], $fx['practitioner'], $this->clinicTime(1, 10),
            ['appointment_status_id' => AppointmentStatus::CANCELLED, 'cancellation_reason' => 'x'],
        ));

        $this->tenantRequest($token, $tenant)->postJson('/api/appointment', [
            'child_id' => $fx['child']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'starts_at' => $this->clinicTime(1, 10),
        ])->assertCreated();
    }

    public function test_update_only_edits_notes_and_never_the_schedule(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $appointment = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(1, 10)));

        $this->tenantRequest($token, $tenant)->putJson("/api/appointment/{$appointment->id}", [
            'notes' => 'Traer cartilla',
            'starts_at' => $this->clinicTime(3, 9),
            'appointment_status_id' => AppointmentStatus::FULFILLED,
        ])->assertOk()
            ->assertJsonPath('data.notes', 'Traer cartilla')
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::SCHEDULED);

        $stored = $this->inTenant($tenant, fn () => Appointment::findOrFail($appointment->id));
        $this->assertTrue($stored->starts_at->equalTo($appointment->starts_at));
    }

    public function test_search_filters_by_date_range_and_practitioner(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $this->inTenant($tenant, function () use ($fx): void {
            $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(1, 10));
            $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(5, 10));
        });

        $this->tenantRequest($token, $tenant)->postJson('/api/appointment/search', [
            'filters' => [
                ['field' => 'practitioner_id', 'operator' => '=', 'value' => $fx['practitioner']->id],
                ['field' => 'starts_at', 'operator' => '>=', 'value' => $this->clinicTime(1, 0)],
                ['field' => 'starts_at', 'operator' => '<', 'value' => $this->clinicTime(2, 0)],
            ],
            'sort' => [['field' => 'starts_at', 'direction' => 'asc']],
        ])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_tenant_isolation_and_permission_gate(): void
    {
        $this->seedPlatformRegistry();
        [$tenantA, , $tenantB] = $this->twoTenants();

        $fx = $this->inTenant($tenantA, fn () => $this->makeChildAndPractitioner());
        $appointment = $this->inTenant($tenantA, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(1, 10)));

        $tokenB = $this->administratorToken($tenantB);
        $this->tenantRequest($tokenB, $tenantB)->getJson("/api/appointment/{$appointment->id}")->assertNotFound();

        $nobody = $this->inTenant($tenantA, fn () => $this->makeUser($tenantA));
        $nobodyToken = $nobody->createToken('nobody', [])->plainTextToken;
        $this->tenantRequest($nobodyToken, $tenantA)->getJson('/api/appointment')->assertForbidden();
        $this->tenantRequest($nobodyToken, $tenantA)->postJson("/api/appointment/{$appointment->id}/confirm")->assertForbidden();
    }

    public function test_soft_deleted_child_or_practitioner_cannot_be_booked(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, function () {
            $fx = $this->makeChildAndPractitioner();
            $fx['child']->delete();

            return $fx;
        });

        $this->tenantRequest($token, $tenant)->postJson('/api/appointment', [
            'child_id' => $fx['child']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'starts_at' => $this->clinicTime(1, 10),
        ])->assertStatus(422)->assertJsonValidationErrors('child_id');
    }

    public function test_health_center_must_be_the_practitioners_own(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $otherCenter = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner()['healthCenter']);

        $this->tenantRequest($token, $tenant)->postJson('/api/appointment', [
            'child_id' => $fx['child']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'health_center_id' => $otherCenter->id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'starts_at' => $this->clinicTime(1, 10),
        ])->assertStatus(422)
            ->assertJsonPath('errors.health_center_id.0', 'El profesional no atiende en ese centro de salud.');
    }
}
