<?php

declare(strict_types=1);

namespace Modules\Appointment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Appointment\Tests\Support\WithAppointmentFixtures;
use Modules\Notification\Models\NotificationUser;
use Tests\TestCase;

/**
 * odd/tasks/edi-agenda.md S5 — "Llegó" notifies the practitioner in-app.
 * Disables the RefreshDatabase transaction wrapper so notifyEvent()'s
 * DB::afterCommit callback fires inside the test (same trick as
 * ReferralNotificationTest).
 */
final class AppointmentArrivalNotificationTest extends TestCase
{
    use RefreshDatabase;
    use WithAppointmentFixtures;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    public function test_arrival_notifies_the_practitioner(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner());
        $appointment = $this->inTenant($tenant, fn () => $this->makeAppointment($fx['child'], $fx['practitioner'], $this->clinicTime(0, 23, 30)));

        $this->tenantRequest($token, $tenant)->postJson("/api/appointment/{$appointment->id}/arrive")->assertOk();

        $this->inTenant($tenant, function () use ($fx): void {
            $recorded = NotificationUser::where('user_id', $fx['practitioner']->user_id)
                ->whereHas('notification', fn ($q) => $q->where('title', 'Paciente en espera'))
                ->exists();

            $this->assertTrue($recorded, 'Expected an in-app notification for the practitioner.');
        });
    }
}
