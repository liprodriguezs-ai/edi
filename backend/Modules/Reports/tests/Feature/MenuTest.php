<?php

declare(strict_types=1);

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Tests\TestCase;

/**
 * REQ-RPT-012 -- menu/routes light up per permission; the aggregate/nominal
 * split IS the menu split (D12).
 */
final class MenuTest extends TestCase
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

    public function test_both_module_rows_are_visible_for_admin_tier(): void
    {
        $this->seedPlatformRegistry();
        [$tenant, $token] = $this->administeredTenant();

        $menu = $this->tenantRequest($token, $tenant)->getJson('/api/menu')->assertOk()->json();
        // print_r (unlike json_encode) does not escape "/" — avoids a false
        // negative on route strings.
        $flat = print_r($menu, true);

        $this->assertStringContainsString('Indicadores', $flat);
        $this->assertStringContainsString('Listado nominal', $flat);
        $this->assertStringContainsString('/reports/indicators', $flat);
        $this->assertStringContainsString('/reports/nominal', $flat);
    }

    public function test_aggregate_only_user_sees_only_indicadores(): void
    {
        $this->seedPlatformRegistry();
        [$tenant] = $this->administeredTenant();
        $token = $this->tokenWithOnly($tenant, ['read.screening_indicator']);

        $menu = $this->tenantRequest($token, $tenant)->getJson('/api/menu')->assertOk()->json();
        $flat = print_r($menu, true);

        $this->assertStringContainsString('Indicadores', $flat);
        $this->assertStringNotContainsString('Listado nominal', $flat);
    }
}
