<?php

namespace Modules\Reports\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Nutrition\Database\Seeders\NutritionSeeder;
use Modules\Reports\Models\ScreeningIndicatorSnapshot;
use Modules\Reports\Services\ChildReportSheet;
use Modules\Reports\Tests\Support\WithReportsFixtures;
use Tests\TestCase;

/**
 * The axis columns of the reports come from the `development_area` catalog,
 * never from a list in code: a new area shows up without touching the reports.
 */
final class ImpactAxesFromCatalogTest extends TestCase
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

    private function addCatalogArea(): void
    {
        DevelopmentArea::query()->forceCreate([
            'id' => 'ed200002-0000-4000-8000-0000000000ff',
            'code' => 'XX',
            'name' => 'Eje de prueba',
            'scoring_kind' => DevelopmentArea::SCORING_KIND_SEMAPHORE,
            'is_development_axis' => true,
            'sort_order' => 99,
        ]);
    }

    public function test_concentrate_lists_its_axes_from_the_catalog(): void
    {
        $this->seedPlatformRegistry();
        (new NutritionSeeder)->run();
        $this->addCatalogArea();
        [$tenant, $token] = $this->administeredTenant();

        $axes = $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/edi-concentrate')
            ->assertOk()
            ->json('meta.impact_axes');

        $this->assertSame(
            ['FRB', 'MG', 'MF', 'LE', 'SO', 'CO', 'EN', 'ALA', 'ALE', 'XX'],
            array_column($axes, 'code')
        );
        $this->assertSame('Factores de riesgo biológico', $axes[0]['name']);
        $this->assertSame('Eje de prueba', $axes[9]['name']);
    }

    public function test_concentrate_export_has_a_column_per_catalog_axis(): void
    {
        $this->seedPlatformRegistry();
        $this->addCatalogArea();

        $headings = app(ChildReportSheet::class)->concentrateHeadings();

        $this->assertContains('Última prueba: XX', $headings);
        $this->assertContains('Prueba anterior: FRB', $headings);
    }

    public function test_indicator_export_has_a_column_per_development_axis_of_the_catalog(): void
    {
        $this->seedPlatformRegistry();
        $this->addCatalogArea();

        $headings = array_keys((new ScreeningIndicatorSnapshot)->reportColumns());

        $this->assertContains('XX (amarillo/rojo)', $headings);
        $this->assertContains('MG (amarillo/rojo)', $headings);
    }
}
