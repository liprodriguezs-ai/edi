<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Screening\Events\ScreeningCompleted;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-016 -- ScreeningCompleted fires exactly once on complete(), never
 * on score/rescore/invalidate. Event::fake() intercepts dispatch WITHOUT
 * running the FollowUp listener, isolating this assertion from the
 * cross-module side effect (covered separately in
 * Modules/FollowUp/tests/Feature/FollowUpCreationFromScreeningTest.php).
 */
final class ScreeningCompletedEventTest extends TestCase
{
    use RefreshDatabase;
    use WithScreeningFixtures;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function scoredScreening(string $token, $tenant): string
    {
        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');

        $answers = $this->g10RedAnswers($version);
        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => $answers])->assertOk();
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")->assertOk();

        return $screeningId;
    }

    public function test_emitted_exactly_once_on_complete(): void
    {
        Event::fake([ScreeningCompleted::class]);

        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $screeningId = $this->scoredScreening($token, $tenant);

        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")->assertOk();

        Event::assertDispatchedTimes(ScreeningCompleted::class, 1);
        Event::assertDispatched(ScreeningCompleted::class, fn (ScreeningCompleted $event) => $event->screening->id === $screeningId);
    }

    public function test_not_emitted_on_score_rescore_or_invalidate(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $screeningId = $this->scoredScreening($token, $tenant);

        Event::fake([ScreeningCompleted::class]);

        // Rescore (non-authoritative what-if) does not complete.
        $otherVersionKey = $this->inTenant($tenant, fn () => InstrumentVersion::where('is_default', false)->whereNotNull('published_at')->value('key'));
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/rescore", [
                'authoritative' => false,
                'instrument_version_key' => $otherVersionKey,
                'reason' => 'what-if',
            ])
            ->assertOk();

        Event::assertNotDispatched(ScreeningCompleted::class);

        // Invalidate a fresh (not yet completed) scored screening.
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/invalidate", ['reason' => 'Duplicado'])
            ->assertOk();

        Event::assertNotDispatched(ScreeningCompleted::class);
    }
}
