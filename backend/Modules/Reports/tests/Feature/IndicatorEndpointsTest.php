<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reports\Services\IndicatorSnapshotService;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Tests\TestCase;

/**
 * REQ-RPT-004/005/008/009.
 */
final class IndicatorEndpointsTest extends TestCase
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

    public function test_closed_month_is_served_from_snapshot_current_month_is_live(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $token] = $this->administeredTenant();
        $version = $this->defaultInstrumentVersion();

        $julyDate = '2026-07-15';
        $fxJuly = $this->inTenant($tenant, fn () => $this->makeChildVisitAt(570, $julyDate));
        $this->applyAndComplete($token, $tenant, $fxJuly['visit'], $this->g10RedAnswers($version));

        $this->inTenant($tenant, function () {
            app(IndicatorSnapshotService::class)->buildPeriod('2026-07');
        });

        $fxNow = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($token, $tenant, $fxNow['visit'], $this->g10GreenAnswers($version));

        $currentPeriod = now()->format('Y-m');

        $series = $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/indicators/monthly-series?months=12')
            ->assertOk()
            ->json('data');

        $julyRow = collect($series)->firstWhere('period', '2026-07');
        $this->assertNotNull($julyRow, 'July must be present in the series.');
        $this->assertSame(1, $julyRow['result_red_count']);

        $currentRow = collect($series)->firstWhere('period', $currentPeriod);
        $this->assertNotNull($currentRow);
        $this->assertSame(1, $currentRow['result_green_count']);
    }

    public function test_aggregate_endpoints_require_screening_indicator_permission(): void
    {
        $this->seedPlatformRegistry();
        [$tenant] = $this->administeredTenant();

        $noPermissionToken = $this->tokenWithOnly($tenant, ['read.nominal_report']);

        $this->tenantRequest($noPermissionToken, $tenant)
            ->getJson('/api/reports/indicators/summary')
            ->assertForbidden();
    }

    public function test_indicator_payload_differs_and_is_cached_per_tenant(): void
    {
        $this->seedPlatformRegistry();
        [$tenantA, $tokenA] = $this->administeredTenant('Organization A');
        [$tenantB, $tokenB] = $this->administeredTenant('Organization B');
        $version = $this->defaultInstrumentVersion();

        $fxA = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $this->applyAndComplete($tokenA, $tenantA, $fxA['visit'], $this->g10RedAnswers($version));

        $summaryA = $this->tenantRequest($tokenA, $tenantA)
            ->getJson('/api/reports/indicators/summary')
            ->assertOk()
            ->json('data');

        $summaryB = $this->tenantRequest($tokenB, $tenantB)
            ->getJson('/api/reports/indicators/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summaryA['screenings_applied']);
        $this->assertSame(0, $summaryB['screenings_applied'], 'Tenant B must never see tenant A screenings (cache key or query leak).');
    }

    public function test_default_period_is_the_tenant_civil_month(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $token] = $this->administeredTenant();
        $tenant->update(['timezone' => 'America/Mexico_City']);

        // Nov 1 03:00 UTC is still Oct 31 21:00 in Mexico City.
        $this->travelTo(Carbon::parse('2026-11-01T03:00:00Z'));

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/indicators/summary')
            ->assertOk()
            ->assertJsonPath('data.period', '2026-10');

        $series = $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/indicators/monthly-series?months=2')
            ->assertOk()
            ->json('data');

        $this->assertSame(['2026-09', '2026-10'], array_column($series, 'period'));
    }
}
