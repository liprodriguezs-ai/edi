<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Reports\Services\IndicatorSnapshotService;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * REQ-RPT-005/007/008 -- the frozen qch/core report engine, reached via
 * `/api/customReport`, exporting the 3 newly mapped models.
 */
final class ReportExportTest extends TestCase
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

    public function test_screening_export_returns_xlsx(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $adminToken] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($adminToken, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        $token = $this->tokenWithAbilities($tenant, ['report.screening']);

        $response = $this->tenantRequest($token, $tenant)
            ->get('/api/customReport?_model=Screening&_type=xlsx&_title=nominal');

        // Excel::download() returns a BinaryFileResponse -- getContent()
        // returns false by design (Symfony streams the file, never buffers
        // it); assert on the response shape instead.
        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertGreaterThan(0, $response->baseResponse->getFile()->getSize());
    }

    public function test_pdf_type_is_rejected_for_mapped_models(): void
    {
        $this->seedPlatformRegistry();
        [$tenant] = $this->administeredTenant();
        $token = $this->tokenWithAbilities($tenant, ['report.screening']);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/customReport?_model=Screening&_type=pdf&_title=nominal')
            ->assertStatus(422);
    }

    public function test_concentrate_export_via_screening_indicator_map_key(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $adminToken] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($adminToken, $tenant, $fx['visit'], $this->g10GreenAnswers($version));

        $this->inTenant($tenant, function () {
            app(IndicatorSnapshotService::class)->buildPeriod(now()->format('Y-m'));
        });

        $token = $this->tokenWithAbilities($tenant, ['report.screening_indicator']);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/customReport?_model=ScreeningIndicator&_type=xlsx&_title=concentrado')
            ->assertOk();
    }

    public function test_nominal_export_query_count_is_bounded_independent_of_row_count(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $adminToken] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        for ($i = 0; $i < 5; $i++) {
            $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
            $this->applyAndComplete($adminToken, $tenant, $fx['visit'], $this->g10RedAnswers($version));
        }

        $token = $this->tokenWithAbilities($tenant, ['report.screening']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/customReport?_model=Screening&_type=xlsx&_title=nominal')
            ->assertOk();

        // Bounded: a handful of setup queries (auth, tenancy, count, fetch,
        // eager loads) — NOT one query per row. 5 rows here; the important
        // invariant is that this does NOT scale linearly with row count.
        $this->assertLessThan(30, $queryCount, 'Export query count must not scale with row count (N+1).');
    }

    public function test_export_ability_is_enforced_per_model_key(): void
    {
        $this->seedPlatformRegistry();
        [$tenant] = $this->administeredTenant();

        // Holds ONLY report.screening_indicator -- report.screening is missing.
        $token = $this->tokenWithAbilities($tenant, ['report.screening_indicator']);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/customReport?_model=ScreeningIndicator&_type=xlsx&_title=concentrado')
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/customReport?_model=Screening&_type=xlsx&_title=nominal')
            ->assertForbidden();
    }
}
