<?php

declare(strict_types=1);

namespace Modules\Appointment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Appointment\Models\AppointmentType;
use Modules\Appointment\Tests\Support\WithAppointmentFixtures;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpType;
use Tests\TestCase;

/**
 * odd/tasks/edi-agenda.md S7 — the next appointment is proposed from the
 * server-computed screening result, never auto-booked.
 */
final class AppointmentSuggestionTest extends TestCase
{
    use RefreshDatabase;
    use WithAppointmentFixtures;

    public function test_yellow_result_suggests_the_reassessment_due_date(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(600));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10YellowAnswers($this->defaultVersion()));

        $followUp = $this->inTenant($tenant, fn () => FollowUp::where('screening_id', $screeningId)
            ->where('follow_up_type_id', FollowUpType::REASSESS_3_MONTHS)->firstOrFail());

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/appointment/suggestion?child_id={$fx['child']->id}")
            ->assertOk()
            ->assertJsonPath('data.reason_key', 'reassessment')
            ->assertJsonPath('data.suggested_date', $followUp->due_date->toDateString())
            ->assertJsonPath('data.follow_up_id', $followUp->id)
            ->assertJsonPath('data.appointment_type_id', AppointmentType::SCREENING);
    }

    public function test_red_result_points_to_the_referral_instead_of_a_routine_rescreen(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(600));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($this->defaultVersion()));

        $referral = $this->inTenant($tenant, fn () => FollowUp::where('screening_id', $screeningId)
            ->where('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL)->firstOrFail());

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/appointment/suggestion?child_id={$fx['child']->id}")
            ->assertOk()
            ->assertJsonPath('data.reason_key', 'referral')
            ->assertJsonPath('data.suggested_date', null)
            ->assertJsonPath('data.follow_up_id', $referral->id);
    }

    public function test_green_or_no_screening_uses_the_well_child_interval_by_age(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();

        // 600 days old (1-4 years band: every 6 months) with a visit today.
        $older = $this->inTenant($tenant, fn () => $this->makeChildVisit(600));
        $this->tenantRequest($token, $tenant)
            ->getJson("/api/appointment/suggestion?child_id={$older['child']->id}")
            ->assertOk()
            ->assertJsonPath('data.reason_key', 'well_child')
            ->assertJsonPath('data.suggested_date', $older['visit']->visit_date->copy()->addDays(180)->toDateString())
            ->assertJsonPath('data.follow_up_id', null);

        // 120 days old, never visited (under 1 year: every 2 months) -> counted from today.
        $baby = $this->inTenant($tenant, fn () => $this->makeChildAndPractitioner(120));
        $this->tenantRequest($token, $tenant)
            ->getJson("/api/appointment/suggestion?child_id={$baby['child']->id}")
            ->assertOk()
            ->assertJsonPath('data.suggested_date', Carbon::parse($this->clinicToday())->addDays(60)->toDateString());
    }

    public function test_an_overdue_well_child_date_is_moved_to_tomorrow(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();
        $fx = $this->inTenant($tenant, function () {
            $fx = $this->makeChildAndPractitioner(900);
            $this->makeVisitFor($fx['child'], Carbon::parse($this->clinicToday())->subDays(400)->toDateString());

            return $fx;
        });

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/appointment/suggestion?child_id={$fx['child']->id}")
            ->assertOk()
            ->assertJsonPath('data.suggested_date', Carbon::parse($this->clinicToday())->addDay()->toDateString());
    }

    public function test_child_must_belong_to_the_tenant(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/appointment/suggestion?child_id=0199a1b2-0000-7000-8000-000000000000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('child_id');
    }
}
