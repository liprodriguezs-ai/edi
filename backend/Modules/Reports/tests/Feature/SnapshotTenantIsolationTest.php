<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Reports\Models\ScreeningIndicatorSnapshot;
use Modules\Reports\Services\IndicatorSnapshotService;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Tests\TestCase;

/**
 * REQ-RPT-009 -- the builder (service AND command) never crosses tenants.
 */
final class SnapshotTenantIsolationTest extends TestCase
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

    public function test_builder_for_tenant_a_writes_only_tenant_a_rows(): void
    {
        $this->seedPlatformRegistry();
        [$tenantA, $tokenA] = $this->administeredTenant('Organization A');
        [$tenantB, $tokenB] = $this->administeredTenant('Organization B');
        $version = $this->defaultInstrumentVersion();

        $fxA = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $fxB = $this->inTenant($tenantB, fn () => $this->makeChildVisit(570));

        $this->applyAndComplete($tokenA, $tenantA, $fxA['visit'], $this->g10RedAnswers($version));
        $this->applyAndComplete($tokenB, $tenantB, $fxB['visit'], $this->g10RedAnswers($version));

        $period = now()->format('Y-m');

        $this->inTenant($tenantA, function () use ($period) {
            app(IndicatorSnapshotService::class)->buildPeriod($period);
        });

        $tenantARows = $this->inTenant($tenantA, fn () => ScreeningIndicatorSnapshot::where('period_start', now()->startOfMonth()->toDateString())->count());
        $tenantBRows = $this->inTenant($tenantB, fn () => ScreeningIndicatorSnapshot::where('period_start', now()->startOfMonth()->toDateString())->count());

        $this->assertGreaterThan(0, $tenantARows);
        $this->assertSame(0, $tenantBRows, 'Building tenant A snapshots must never write a row visible under tenant B scope.');
    }

    public function test_command_with_two_tenants_never_crosses(): void
    {
        $this->seedPlatformRegistry();
        [$tenantA, $tokenA] = $this->administeredTenant('Organization A');
        [$tenantB, $tokenB] = $this->administeredTenant('Organization B');
        $version = $this->defaultInstrumentVersion();

        $fxA = $this->inTenant($tenantA, fn () => $this->makeChildVisit(570));
        $fxB = $this->inTenant($tenantB, fn () => $this->makeChildVisit(570));

        $this->applyAndComplete($tokenA, $tenantA, $fxA['visit'], $this->g10RedAnswers($version));
        $this->applyAndComplete($tokenB, $tenantB, $fxB['visit'], $this->g10GreenAnswers($version));

        Artisan::call('reports:build-indicator-snapshots', ['--months' => 1]);

        $rollupA = $this->inTenant($tenantA, fn () => ScreeningIndicatorSnapshot::whereNull('health_center_id')->where('period_start', now()->startOfMonth()->toDateString())->first());
        $rollupB = $this->inTenant($tenantB, fn () => ScreeningIndicatorSnapshot::whereNull('health_center_id')->where('period_start', now()->startOfMonth()->toDateString())->first());

        $this->assertNotNull($rollupA);
        $this->assertNotNull($rollupB);
        $this->assertSame(1, $rollupA->result_red_count);
        $this->assertSame(0, $rollupA->result_green_count);
        $this->assertSame(0, $rollupB->result_red_count);
        $this->assertSame(1, $rollupB->result_green_count);
    }
}
