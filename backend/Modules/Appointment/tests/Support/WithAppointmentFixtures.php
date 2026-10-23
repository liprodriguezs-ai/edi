<?php

declare(strict_types=1);

namespace Modules\Appointment\Tests\Support;

use Illuminate\Support\Carbon;
use Modules\Appointment\Models\Appointment;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\Appointment\Models\AppointmentType;
use Modules\ChildRecord\Models\Child;
use Modules\Clinic\Database\Factories\HealthCenterFactory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Modules\Tenant\Models\Tenant;

/**
 * Appointment test fixtures: reuses the FollowUp/Screening fixtures for
 * platform seeding, tenant setup and the real screening flow, and adds a
 * child + practitioner pair plus clinic-local time helpers.
 */
trait WithAppointmentFixtures
{
    use WithFollowUpFixtures;

    /**
     * The clinic's wall-clock zone. Defaults to the tenant column default and is
     * refreshed from the real tenant row by bootTenant(); the app timezone is
     * UTC under test, so mixing the two shifts "today" near midnight.
     */
    protected string $clinicTimezone = 'America/Mexico_City';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * @return array{tenant: Tenant, token: string}
     */
    protected function bootTenant(): array
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $this->clinicTimezone = (string) $tenant->fresh()->timezone;

        return ['tenant' => $tenant, 'token' => $this->administratorToken($tenant)];
    }

    /**
     * @return array{child: Child, practitioner: PractitionerProfile, healthCenter: HealthCenter}
     */
    protected function makeChildAndPractitioner(int $ageDays = 600): array
    {
        $healthCenter = HealthCenter::factory()->create([
            'municipality_id' => HealthCenterFactory::defaultMunicipality()->id,
        ]);

        $child = Child::factory()->create([
            'health_center_id' => $healthCenter->id,
            'birth_date' => now()->subDays($ageDays)->format('Y-m-d'),
        ]);

        $practitioner = PractitionerProfile::factory()->create(['health_center_id' => $healthCenter->id]);

        return compact('child', 'practitioner', 'healthCenter');
    }

    /**
     * A wall-clock moment in the clinic timezone, serialized with its offset
     * exactly as the browser sends it.
     */
    protected function clinicTime(int $daysFromToday, int $hour, int $minute = 0): string
    {
        return Carbon::now($this->clinicTimezone)
            ->addDays($daysFromToday)
            ->setTime($hour, $minute)
            ->toIso8601String();
    }

    protected function clinicToday(): string
    {
        return Carbon::now($this->clinicTimezone)->toDateString();
    }

    /**
     * Persists an appointment directly (bypassing the "start must be in the
     * future" booking rule) so past/no-show scenarios can be arranged.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeAppointment(Child $child, PractitionerProfile $practitioner, string $startsAt, array $attributes = []): Appointment
    {
        $start = Carbon::parse($startsAt);

        return Appointment::factory()->create([
            'child_id' => $child->id,
            'health_center_id' => $practitioner->health_center_id,
            'practitioner_id' => $practitioner->id,
            'appointment_type_id' => AppointmentType::SCREENING,
            'appointment_status_id' => AppointmentStatus::SCHEDULED,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(30),
            ...$attributes,
        ]);
    }
}
