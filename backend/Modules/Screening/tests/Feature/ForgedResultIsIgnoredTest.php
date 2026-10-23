<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Instrument\Models\ResultColor;
use Modules\Screening\Models\Screening;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-003 — MANDATORY test. The result NEVER comes from the client:
 * forged result_color_id/situation_id/counters/scored_at across create,
 * answer, and score payloads over red-producing answers must be ignored,
 * and the stored+returned result_color_id must be red.
 */
final class ForgedResultIsIgnoredTest extends TestCase
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

    public function test_forged_fields_are_ignored_and_the_true_red_result_persists(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $forgedGreenId = ResultColor::GREEN;

        $create = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', [
                'visit_id' => $fx['visit']->id,
                // Not in $fillable — must be silently ignored.
                'result_color_id' => $forgedGreenId,
                'situation_id' => 'ed300002-0000-4000-8000-000000000001',
                'alert_sign_count' => 0,
                'risk_factor_count' => 0,
                'scored_at' => now()->subYear()->toIso8601String(),
            ])
            ->assertCreated();

        $screeningId = $create->json('data.id');
        $this->assertNull($create->json('data.result_color_id'));

        $answers = $this->g10RedAnswers($version);
        // Client injects a forged color/derived flag on the answer payload —
        // the FormRequest whitelist only allows item_id/option_id, so these
        // extra keys never reach the service at all.
        $forgedAnswers = array_map(fn ($a) => [...$a, 'result_color_id' => $forgedGreenId, 'is_auto_derived' => true], $answers);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$screeningId}/answer", [
                'answers' => $forgedAnswers,
                'result_color_id' => $forgedGreenId,
            ])
            ->assertOk();

        $score = $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$screeningId}/score", [
                'result_color_id' => $forgedGreenId,
                'situation_id' => 'ed300002-0000-4000-8000-000000000001',
                'alert_sign_count' => 999,
                'risk_factor_count' => 999,
                'scored_at' => now()->subYear()->toIso8601String(),
            ])
            ->assertOk();

        $score->assertJsonPath('data.result_color_id', ResultColor::RED);
        $this->assertNotSame($forgedGreenId, $score->json('data.result_color_id'));

        $this->inTenant($tenant, function () use ($screeningId) {
            $stored = Screening::findOrFail($screeningId);
            $this->assertSame(ResultColor::RED, $stored->result_color_id);
        });
    }
}
