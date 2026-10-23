<?php

declare(strict_types=1);

namespace Modules\Nutrition\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ChildRecord\Models\Sex;
use Modules\Nutrition\Database\Seeders\NutritionSeeder;
use Modules\Nutrition\Models\HeightForAgeReference;
use Modules\Nutrition\Models\NutritionalStatus;
use Modules\Nutrition\Models\WeightForAgeReference;
use Modules\Nutrition\Models\WeightForHeightReference;
use Tests\TestCase;

final class NutritionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_the_who_tables_and_the_status_catalog_idempotently(): void
    {
        (new NutritionSeeder)->run();
        (new NutritionSeeder)->run();

        $this->assertSame(524, WeightForHeightReference::count());
        $this->assertSame(122, WeightForAgeReference::count());
        $this->assertSame(122, HeightForAgeReference::count());
        $this->assertSame(12, NutritionalStatus::count());

        $this->assertSame('Desnutrición grave', NutritionalStatus::find(NutritionalStatus::SEVERE_MALNUTRITION)->name);
        $this->assertSame('no_reference', NutritionalStatus::find(NutritionalStatus::NO_REFERENCE)->key);
    }

    public function test_reference_values_are_stored_in_grams_and_millimetres(): void
    {
        (new NutritionSeeder)->run();

        $row = WeightForHeightReference::where('sex_id', Sex::MALE)
            ->where('band', 'under_24_months')
            ->where('height_mm', 725)
            ->firstOrFail();

        $this->assertSame(9000, $row->median);
        $this->assertSame(9800, $row->sd_plus1);
    }
}
