<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Screening\Services\Scoring\ScoringEngine;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * REQ-SCR-015 — the official FUA document.
 *
 * It is a PROJECTION of what was stored, never a recomputation: a screening
 * applied months ago must print exactly what was recorded then, even if the
 * instrument was re-seeded since. Re-running the engine at print time would let
 * a document silently disagree with the record it claims to represent.
 */
final class ScreeningDocumentTest extends TestCase
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

    /**
     * @return array{0: Tenant, 1: string, 2: string}
     */
    private function scoredScreening(): array
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $version = $this->defaultVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));

        $id = $this->tenantRequest($token, $tenant)
            ->postJson('/api/screening', ['visit_id' => $fx['visit']->id])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/screening/{$id}/answer", ['answers' => $this->g10RedAnswers($version)])
            ->assertOk();

        return [$tenant, $token, $id];
    }

    public function test_a_draft_screening_has_no_document_yet(): void
    {
        [$tenant, $token, $id] = $this->scoredScreening();

        // Still a draft: nothing has been scored, so there is no result to print.
        $this->tenantRequest($token, $tenant)
            ->get("/api/screening/{$id}/document")
            ->assertStatus(409);
    }

    public function test_a_scored_screening_renders_its_document(): void
    {
        [$tenant, $token, $id] = $this->scoredScreening();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$id}/score")
            ->assertOk();

        $response = $this->tenantRequest($token, $tenant)
            ->get("/api/screening/{$id}/document")
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * The scenario REQ-SCR-015 spells out: printing must not touch the engine.
     */
    public function test_the_document_never_invokes_the_scoring_engine(): void
    {
        [$tenant, $token, $id] = $this->scoredScreening();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/screening/{$id}/score")
            ->assertOk();

        // Any engine call while rendering is a failure, not a detail: it would mean
        // the document can disagree with the stored record.
        $this->app->bind(ScoringEngine::class, function (): never {
            throw new \LogicException('The document re-ran the scoring engine.');
        });

        $this->tenantRequest($token, $tenant)
            ->get("/api/screening/{$id}/document")
            ->assertOk();
    }
}
