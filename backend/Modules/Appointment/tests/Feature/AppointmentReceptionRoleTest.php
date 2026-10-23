<?php

declare(strict_types=1);

namespace Modules\Appointment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\Appointment\Models\AppointmentType;
use Modules\Appointment\Tests\Support\WithAppointmentFixtures;
use Modules\ChildRecord\Models\BirthRecord;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role;
use Tests\TestCase;

/**
 * odd/tasks/edi-agenda.md S14 — the front-desk role runs the agenda without
 * clinical powers: it books, confirms, marks arrival and reschedules, but never
 * starts care (that creates the visit) nor touches screenings.
 */
final class AppointmentReceptionRoleTest extends TestCase
{
    use RefreshDatabase;
    use WithAppointmentFixtures;

    private function receptionToken(Tenant $tenant): string
    {
        $user = $this->inTenant($tenant, fn () => $this->makeUser($tenant));
        $user->assignRole(Role::SLUG_ASSISTANT);

        return $user->createToken('reception', $user->getPermissionsViaRoles()->pluck('name')->toArray())->plainTextToken;
    }

    public function test_reception_runs_the_agenda_but_cannot_start_care(): void
    {
        ['tenant' => $tenant] = $this->bootTenant();
        $token = $this->receptionToken($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $api = fn () => $this->tenantRequest($token, $tenant);

        $id = $api()->postJson('/api/appointment', [
            'child_id' => $fx['child']->id,
            'practitioner_id' => $fx['practitioner']->id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'starts_at' => $this->clinicTime(1, 10),
        ])->assertCreated()->json('data.id');

        $api()->postJson("/api/appointment/{$id}/confirm")->assertOk();
        $api()->postJson("/api/appointment/{$id}/reschedule", ['starts_at' => $this->clinicTime(2, 11)])->assertOk();

        $today = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(0, 23, 30)));
        $api()->postJson("/api/appointment/{$today->id}/arrive")->assertOk()
            ->assertJsonPath('data.appointment_status_id', AppointmentStatus::ARRIVED);

        $api()->postJson("/api/appointment/{$today->id}/start")->assertForbidden();
        $api()->getJson('/api/screening')->assertForbidden();
        $api()->getJson('/api/child')->assertOk();
    }

    public function test_administrator_can_hand_out_the_reception_role(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();

        $response = $this->tenantRequest($token, $tenant)->postJson('/api/selects', ['role' => []])->assertOk();
        $roles = $response->json('data.role') ?? $response->json('role') ?? [];

        $this->assertContains(Role::SLUG_ASSISTANT, array_column($roles, 'name'));
    }

    /**
     * S16 — perinatal history is clinical: the front desk may read it but never
     * write it.
     */
    public function test_reception_reads_but_cannot_write_the_birth_record(): void
    {
        ['tenant' => $tenant] = $this->bootTenant();
        $token = $this->receptionToken($tenant);
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $other = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner()['child']);
        $record = $this->inTenant($tenant, fn () => BirthRecord::factory()->create(['child_id' => $fx['child']->id]));
        $api = fn () => $this->tenantRequest($token, $tenant);

        $api()->getJson("/api/birth_record/{$record->id}")->assertOk();
        $api()->postJson('/api/birth_record', ['child_id' => $other->id, 'gestational_age_weeks' => 38])->assertForbidden();
        $api()->putJson("/api/birth_record/{$record->id}", ['gestational_age_weeks' => 30])->assertForbidden();

        $this->assertNotSame(30, $this->inTenant($tenant, fn () => BirthRecord::findOrFail($record->id)->gestational_age_weeks));
    }

    /**
     * S17 — the front-desk menu shows only what it operates.
     */
    public function test_reception_menu_hides_dashboard_and_clinic_configuration(): void
    {
        ['tenant' => $tenant] = $this->bootTenant();
        $token = $this->receptionToken($tenant);

        $names = collect($this->tenantRequest($token, $tenant)->getJson('/api/menu')->assertOk()->json('data'))
            ->flatMap(fn ($sys) => collect($sys['items'] ?? [])->flatMap(
                fn ($item) => ($item['type'] ?? null) === 'group' ? $item['modules'] : [$item]
            ))
            ->pluck('name')
            ->all();

        $this->assertContains('Agenda', $names);
        $this->assertContains('Niños', $names);
        $this->assertNotContains('Dashboard', $names);
        $this->assertNotContains('Centros de Salud', $names);
        $this->assertNotContains('Profesionales de Salud', $names);
    }
}
