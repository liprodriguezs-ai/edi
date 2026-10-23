<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Support;

use Illuminate\Support\Carbon;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;

/**
 * FollowUp test fixtures (edi-followup-and-referral): reuses the Screening
 * module's fixtures for platform seeding + tenant setup, and adds
 * color-controlled answer sets that stay within the G10 group boundaries
 * (570-749 days) so a child can accumulate several dated visits/screenings
 * without leaving the group used by g10RedAnswers/g10GreenAnswers.
 */
trait WithFollowUpFixtures
{
    use WithScreeningFixtures;
    use WithTenantSetup;

    /**
     * Creates a Visit for an EXISTING child at a given visit_date (unlike
     * WithScreeningFixtures::makeChildVisit, which always dates today).
     */
    protected function makeVisitFor(Child $child, string $visitDate): Visit
    {
        $healthCenter = $child->health_center;
        $practitioner = PractitionerProfile::factory()->create([
            'health_center_id' => $healthCenter->id,
        ]);

        return Visit::factory()->create([
            'child_id' => $child->id,
            'health_center_id' => $healthCenter->id,
            'practitioner_id' => $practitioner->id,
            'visit_date' => $visitDate,
        ]);
    }

    /**
     * Full apply-answer-score-complete flow via the real API, returning the
     * completed screening's id. $answers is one of the
     * g10RedAnswers/g10GreenAnswers/g10YellowAnswers builders below.
     */
    protected function applyAndComplete(string $token, Tenant $tenant, Visit $visit, array $answers): string
    {
        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $visit->id])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/complete")
            ->assertOk();

        return $screeningId;
    }

    /**
     * G10 answers scoring a clean YELLOW global result: MG axis gets exactly
     * one red + one yellow + one own item (not all-yellow, so it never
     * triggers the alternate-evaluation gate), every other semaphore area
     * stays green -- global rule 20 (yellow_development) fires because MG
     * is yellow.
     *
     * @return array<int, array{screening_item_id: string, screening_item_option_id: string}>
     */
    protected function g10YellowAnswers(InstrumentVersion $version): array
    {
        return [
            $this->answerByColor($version, 'G10', 'MG10.1', 'yellow'),
            $this->answerByColor($version, 'G10', 'MG10.2', 'yellow'),
            $this->answerFor($version, 'G10', 'MG10.3', 'SI'),
            $this->answerFor($version, 'G10', 'MF10.1', 'SI'),
            $this->answerFor($version, 'G10', 'MF10.2', 'SI'),
            $this->answerFor($version, 'G10', 'MF10.3', 'SI'),
            $this->answerFor($version, 'G10', 'LE10.1', 'SI'),
            $this->answerFor($version, 'G10', 'LE10.2', 'SI'),
            $this->answerFor($version, 'G10', 'LE10.3', 'SI'),
            $this->answerFor($version, 'G10', 'SO10.1', 'SI'),
            $this->answerFor($version, 'G10', 'SO10.2', 'SI'),
            $this->answerFor($version, 'G10', 'SO10.3', 'SI'),
            $this->answerFor($version, 'G10', 'EN10.1', 'NO'),
            $this->answerFor($version, 'G10', 'EN10.2', 'NO'),
            $this->answerFor($version, 'G10', 'EN10.3', 'NO'),
            $this->answerFor($version, 'G10', 'ALA10.1', 'NO'),
            $this->answerFor($version, 'G10', 'ALA10.2', 'NO'),
            $this->answerFor($version, 'G10', 'ALE10.1', 'NO'),
            $this->answerFor($version, 'G10', 'ALE10.2', 'NO'),
            $this->answerFor($version, 'G10', 'ALE10.3', 'NO'),
        ];
    }

    /**
     * Resolves the option of $itemCode whose result_color matches $colorKey
     * (green/yellow/red), independent of the item's specific label text.
     *
     * @return array{screening_item_id: string, screening_item_option_id: string}
     */
    protected function answerByColor(InstrumentVersion $version, string $groupCode, string $itemCode, string $colorKey): array
    {
        $group = AgeGroup::where('instrument_version_id', $version->id)->where('code', $groupCode)->firstOrFail();
        $item = ScreeningItem::where('age_group_id', $group->id)->where('code', $itemCode)->firstOrFail();

        $colorId = ResultColor::where('key', $colorKey)->value('id');

        $option = ScreeningItemOption::where('screening_item_id', $item->id)
            ->where('result_color_id', $colorId)
            ->firstOrFail();

        return ['screening_item_id' => $item->id, 'screening_item_option_id' => $option->id];
    }

    protected function assertDateIsToday(?string $date): void
    {
        $this->assertSame(Carbon::today()->toDateString(), Carbon::parse($date)->toDateString());
    }
}
