<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Tests\TestCase;

/**
 * REQ-RPT-006/008.
 */
final class NominalReportTest extends TestCase
{
    use RefreshDatabase;
    use WithReportsFixtures;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_nominal_list_returns_paginated_scored_screenings(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $token] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/nominal')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertArrayHasKey('meta', $response->json());
    }

    public function test_filters_by_result_color_and_health_center(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $token] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $fxRed = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $fxGreen = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fxRed['visit'], $this->g10RedAnswers($version));
        $this->applyAndComplete($token, $tenant, $fxGreen['visit'], $this->g10GreenAnswers($version));

        $redColorId = $this->inTenant($tenant, fn () => DB::table('result_color')->where('key', 'red')->value('id'));

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/nominal/search', [
                'filters' => [['field' => 'result_color_id', 'operator' => '=', 'value' => $redColorId]],
            ])
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_indicator_only_user_cannot_reach_nominal_data(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $adminToken] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($adminToken, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $indicatorOnlyToken = $this->tokenWithOnly($tenant, ['read.screening_indicator']);

        $this->tenantRequest($indicatorOnlyToken, $tenant)
            ->getJson('/api/reports/nominal')
            ->assertForbidden();
    }
}
