<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningScoreRun;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-009/010/011 — freeze matrix, rescore auditability/version-pinning/
 * fingerprint, invalidate terminal.
 */
final class ScreeningLifecycleTest extends TestCase
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

    public function test_complete_requires_scored_status(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $draftId = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$draftId}/complete")
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
    }

    public function test_completed_freezes_answer_score_and_complete(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $screeningId = $this->scoredScreening($token, $tenant);
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")->assertOk();

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", ['answers' => []])
            ->assertStatus(409)->assertJsonPath('code', 'SCREENING_FROZEN');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score")
            ->assertStatus(409)->assertJsonPath('code', 'SCREENING_FROZEN');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/complete")
            ->assertStatus(409)->assertJsonPath('code', 'SCREENING_FROZEN');
    }

    public function test_rescore_requires_permission_reason_and_own_version(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $screeningId = $this->scoredScreening($token, $tenant);
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")->assertOk();

        // No permission: a fresh user with a token carrying NO abilities at all.
        $noPermUser = $this->inTenant($tenant, function () use ($tenant) {
            return $this->makeUser($tenant);
        });
        $noPermToken = $noPermUser->createToken('nobody', [])->plainTextToken;

        $this->tenantRequest($noPermToken, $tenant)
            ->postJson("/api/screening/{$screeningId}/rescore", ['authoritative' => true, 'reason' => 'x'])
            ->assertStatus(403);

        // Permission but no reason.
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/rescore", ['authoritative' => true])
            ->assertStatus(422);

        // Authoritative + a foreign version key.
        $foreignVersionKey = $this->inTenant($tenant, fn () => InstrumentVersion::where('is_default', false)->whereNotNull('published_at')->value('key'));
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/rescore", [
                'authoritative' => true,
                'instrument_version_key' => $foreignVersionKey,
                'reason' => 'x',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VERSION_PINNED');

        $this->inTenant($tenant, function () use ($screeningId) {
            $this->assertSame(1, ScreeningScoreRun::where('screening_id', $screeningId)->count());
        });
    }

    public function test_non_authoritative_what_if_leaves_the_header_byte_identical(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $screeningId = $this->scoredScreening($token, $tenant);
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")->assertOk();

        $before = $this->inTenant($tenant, fn () => Screening::findOrFail($screeningId)->getAttributes());

        $otherVersionKey = $this->inTenant($tenant, fn () => InstrumentVersion::where('is_default', false)->whereNotNull('published_at')->value('key'));

        $response = $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/rescore", [
                'authoritative' => false,
                'instrument_version_key' => $otherVersionKey,
                'reason' => 'Simulación what-if',
            ])
            ->assertOk();

        $this->assertFalse($response->json('run.is_authoritative'));
        $this->assertSame($otherVersionKey, $response->json('run.instrument_version.key') ?? null);

        $after = $this->inTenant($tenant, fn () => Screening::findOrFail($screeningId)->getAttributes());
        unset($before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);

        $this->inTenant($tenant, function () use ($screeningId) {
            $this->assertSame(2, ScreeningScoreRun::where('screening_id', $screeningId)->count());
            $simulation = ScreeningScoreRun::where('screening_id', $screeningId)->where('is_authoritative', false)->sole();
            $this->assertSame('simulation', $simulation->trigger);
        });
    }

    public function test_authoritative_rescore_updates_header_but_never_status(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $screeningId = $this->scoredScreening($token, $tenant);
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")->assertOk();

        $response = $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/rescore", ['authoritative' => true, 'reason' => 'Auditoría'])
            ->assertOk();

        $response->assertJsonPath('data.screening_status_id', ScreeningStatus::COMPLETED);

        $this->inTenant($tenant, function () use ($screeningId) {
            $this->assertSame(2, ScreeningScoreRun::where('screening_id', $screeningId)->count());
            $run = ScreeningScoreRun::where('screening_id', $screeningId)->where('is_authoritative', true)->latest('created_at')->first();
            $this->assertSame('rescore', $run->trigger);
            $this->assertSame('Auditoría', $run->reason);
        });
    }

    public function test_invalidate_is_terminal_and_freezes_every_mutation(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $screeningId = $this->scoredScreening($token, $tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/invalidate", ['reason' => 'Duplicado'])
            ->assertOk()
            ->assertJsonPath('data.screening_status_id', ScreeningStatus::INVALIDATED);

        $this->tenantRequest($token, $tenant)->putJson("/api/screening/{$screeningId}/answer", ['answers' => []])
            ->assertStatus(409)->assertJsonPath('code', 'SCREENING_FROZEN');
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/score")
            ->assertStatus(409)->assertJsonPath('code', 'SCREENING_FROZEN');
        $this->tenantRequest($token, $tenant)->postJson("/api/screening/{$screeningId}/complete")
            ->assertStatus(409)->assertJsonPath('code', 'SCREENING_FROZEN');
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/rescore", ['authoritative' => false, 'reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('code', 'SCREENING_FROZEN');
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/invalidate", ['reason' => 'y'])
            ->assertStatus(409)->assertJsonPath('code', 'SCREENING_FROZEN');

        // Reads keep working.
        $this->tenantRequest($token, $tenant)->getJson("/api/screening/{$screeningId}")->assertOk();
    }
}
