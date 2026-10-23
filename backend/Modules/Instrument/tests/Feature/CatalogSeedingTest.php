<?php

declare(strict_types=1);

namespace Modules\Instrument\Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Instrument\Models\ApplicationPosition;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\ResultColor;
use Tests\TestCase;

/**
 * REQ-INS-... support catalogs (design §3.1, task 1.4). result_color,
 * development_area, application_position are central, NOT versioned (D2/D3).
 */
final class CatalogSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_catalogs_are_seeded_with_fixed_uuids(): void
    {
        (new CatalogSeeder)->run();

        $this->assertSame('Verde', ResultColor::find(ResultColor::GREEN)?->name);
        $this->assertSame('Amarillo', ResultColor::find(ResultColor::YELLOW)?->name);
        $this->assertSame('Rojo', ResultColor::find(ResultColor::RED)?->name);
        $this->assertSame(3, ResultColor::count());

        $this->assertSame(8, DevelopmentArea::count());
        $this->assertSame(6, ApplicationPosition::count());
    }

    public function test_reseeding_support_catalogs_is_idempotent(): void
    {
        (new CatalogSeeder)->run();
        (new CatalogSeeder)->run();

        $this->assertSame(3, ResultColor::count());
        $this->assertSame(8, DevelopmentArea::count());
        $this->assertSame(6, ApplicationPosition::count());
    }

    public function test_development_area_has_exactly_one_counter_and_five_development_axes(): void
    {
        (new CatalogSeeder)->run();

        $counters = DevelopmentArea::where('scoring_kind', DevelopmentArea::SCORING_KIND_COUNTER)->get();
        $this->assertCount(1, $counters);
        $this->assertSame('ALE', $counters->first()->code);

        $axes = DevelopmentArea::where('is_development_axis', true)->pluck('code')->sort()->values()->all();
        $this->assertSame(['CO', 'LE', 'MF', 'MG', 'SO'], $axes);
    }
}
