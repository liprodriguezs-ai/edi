<?php

declare(strict_types=1);

namespace Modules\Appointment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Appointment\Models\Appointment;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\Appointment\Models\AppointmentType;
use Modules\Appointment\Tests\Support\WithAppointmentFixtures;
use Modules\ChildRecord\Models\Visit;
use Tests\TestCase;

/**
 * odd/tasks/edi-agenda.md — S2 (FSM), S5 (arrival, walk-in), S6 (start of care), S8 (no-show).
 */
final class AppointmentLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use WithAppointmentFixtures;

    public function test_confirm_arrive_and_start_creates_the_visit_with_its_folio(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $appointment = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(0, 23, 0)));
        $api = fn () => $this->tenantRequest($token, $tenant);

        $api()->postJson("/api/appointment/{$appointment->id}/confirm")->assertOk()
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::CONFIRMED);

        $arrived = $api()->postJson("/api/appointment/{$appointment->id}/arrive")->assertOk()
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::ARRIVED);
        $this->assertNotNull($arrived->json('data.arrived_at'));

        $started = $api()->postJson("/api/appointment/{$appointment->id}/start")->assertOk()
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::FULFILLED);

        $visitId = $started->json('data.visit_id');
        $this->assertNotNull($visitId);
        $started->assertJsonPath('data.visit.id', $visitId);

        $visit = $this->inTenant($tenant, fn () => Visit::findOrFail($visitId));
        $this->assertMatchesRegularExpression('/^V-\d{4}-\d{6}$/', $visit->receipt_number);
        $this->assertSame($fx['child']->id, $visit->child_id);
        $this->assertSame($fx['practitioner']->id, $visit->practitioner_id);
        $this->assertSame($appointment->health_center_id, $visit->health_center_id);
        $this->assertSame($this->clinicToday(), $visit->visit_date->toDateString());

        // Fulfilled is terminal.
        $api()->postJson("/api/appointment/{$appointment->id}/start")->assertStatus(409)
            ->assertJsonPath('code', 'APPOINTMENT_FROZEN');
    }

    public function test_start_without_front_desk_arrival_stamps_arrival_too(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $appointment = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(0, 23, 0)));

        $started = $this->tenantRequest($token, $tenant)->postJson("/api/appointment/{$appointment->id}/start")->assertOk();

        $this->assertNotNull($started->json('data.arrived_at'));
        $this->assertNotNull($started->json('data.visit_id'));
    }

    public function test_arrival_and_start_are_only_allowed_on_the_appointment_day(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $nextWeek = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(7, 10)));

        $this->tenantRequest($token, $tenant)->postJson("/api/appointment/{$nextWeek->id}/arrive")
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
        $this->tenantRequest($token, $tenant)->postJson("/api/appointment/{$nextWeek->id}/start")
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');

        $this->assertSame(0, $this->inTenant($tenant, fn () => Visit::count()));
    }

    public function test_cancel_requires_a_reason_and_freezes_the_appointment(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $appointment = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(1, 10)));
        $api = fn () => $this->tenantRequest($token, $tenant);

        $api()->postJson("/api/appointment/{$appointment->id}/cancel", [])->assertStatus(422);

        $cancelled = $api()->postJson("/api/appointment/{$appointment->id}/cancel", ['reason' => 'La mamá no puede'])->assertOk()
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::CANCELLED)
            ->assertJsonPath('data.cancellation_reason', 'La mamá no puede');
        $this->assertNotNull($cancelled->json('data.cancelled_at'));

        $api()->postJson("/api/appointment/{$appointment->id}/confirm")->assertStatus(409)
            ->assertJsonPath('code', 'APPOINTMENT_FROZEN');
        $api()->putJson("/api/appointment/{$appointment->id}", ['notes' => 'x'])->assertStatus(409);
    }

    public function test_reschedule_moves_the_slot_and_resets_confirmation(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $appointment = $this->inTenant($tenant, fn () => $this->makeAppointment(
            $fx['child'], $fx['practitioner'], $this->clinicTime(1, 10),
            ['appointment_status_id' => AppointmentStatus::CONFIRMED, 'confirmed_at' => now()],
        ));
        $blocker = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(2, 9)));
        $api = fn () => $this->tenantRequest($token, $tenant);

        $api()->postJson("/api/appointment/{$appointment->id}/reschedule", ['starts_at' => $this->clinicTime(-1, 9)])
            ->assertStatus(422)->assertJsonValidationErrors('starts_at');

        $api()->postJson("/api/appointment/{$appointment->id}/reschedule", ['starts_at' => $this->clinicTime(2, 9, 15)])
            ->assertStatus(422)->assertJsonPath('errors.starts_at.0', 'El profesional ya tiene una cita en ese horario.');

        $moved = $api()->postJson("/api/appointment/{$appointment->id}/reschedule", ['starts_at' => $this->clinicTime(3, 12)])
            ->assertOk()
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::SCHEDULED)
            ->assertJsonPath('data.confirmed_at', null);

        $this->assertTrue(Carbon::parse($moved->json('data.starts_at'))->equalTo(Carbon::parse($this->clinicTime(3, 12))));
        $this->assertSame(30, (int) Carbon::parse($moved->json('data.starts_at'))->diffInMinutes(Carbon::parse($moved->json('data.ends_at'))));
        $this->assertNotNull($blocker->id);
    }

    public function test_walk_in_is_born_arrived_and_never_blocks_the_agenda(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], now()->addMinutes(5)->toIso8601String()));

        $walkIn = $this->tenantRequest($token, $tenant)->postJson('/api/appointment/walk_in', [
            'child_id' => $fx['child']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'appointment_type_id' => AppointmentType::SCREENING,
        ])->assertCreated()
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::ARRIVED)
            ->assertJsonPath('data.is_walk_in', true);

        $this->assertNotNull($walkIn->json('data.arrived_at'));
    }

    public function test_no_show_only_after_the_start_time(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $future = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(1, 10)));
        $past = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], now()->subHours(2)->toIso8601String()));

        $this->tenantRequest($token, $tenant)->postJson("/api/appointment/{$future->id}/no_show")
            ->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');

        $this->tenantRequest($token, $tenant)->postJson("/api/appointment/{$past->id}/no_show")->assertOk()
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::NO_SHOW);
    }

    public function test_bulk_no_show_closes_the_days_unarrived_appointments_only(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $yesterday = Carbon::now($this->clinicTimezone)->subDay();

        $ids = $this->inTenant($tenant, function () use ($fx, $yesterday): array {
            $at = fn (int $h) => $yesterday->copy()->setTime($h, 0)->toIso8601String();

            return [
                'missed' => $this->makeAppointment($fx['child'], $fx['practitioner'], $at(9))->id,
                'confirmedMissed' => $this->makeAppointment($fx['child'], $fx['practitioner'], $at(10), ['appointment_status_id' => AppointmentStatus::CONFIRMED])->id,
                'arrived' => $this->makeAppointment($fx['child'], $fx['practitioner'], $at(11), ['appointment_status_id' => AppointmentStatus::ARRIVED, 'arrived_at' => now()])->id,
                'otherDay' => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(1, 10))->id,
            ];
        });

        $this->tenantRequest($token, $tenant)->postJson('/api/appointment/mark_no_show', [
            'date' => $yesterday->toDateString(),
        ])->assertOk()->assertJsonPath('data.updated', 2);

        $status = fn (string $id) => $this->inTenant($tenant, fn () => Appointment::findOrFail($id)->appointment_status_id);
        $this->assertSame(AppointmentStatus::NO_SHOW, $status($ids['missed']));
        $this->assertSame(AppointmentStatus::NO_SHOW, $status($ids['confirmedMissed']));
        $this->assertSame(AppointmentStatus::ARRIVED, $status($ids['arrived']));
        $this->assertSame(AppointmentStatus::SCHEDULED, $status($ids['otherDay']));
    }

    public function test_reschedule_to_another_practitioner_moves_the_health_center_too(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $other = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner()['practitioner']);
        $appointment = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(1, 10)));

        $this->tenantRequest($token, $tenant)->postJson("/api/appointment/{$appointment->id}/reschedule", [
            'starts_at' => $this->clinicTime(2, 10),
            'practitioner_id' => $other->id,
        ])->assertOk()
            ->assertJsonPath('data.practitioner_id', $other->id)
            ->assertJsonPath('data.health_center_id', $other->health_center_id);
    }
}
