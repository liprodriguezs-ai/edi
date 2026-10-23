<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Instrument\Models\ApplicationPosition;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\ScreeningItem;

/**
 * @extends Factory<ScreeningItem>
 *
 * Callers building a real (version, group) tree should override BOTH
 * instrument_version_id and age_group_id explicitly -- the independent
 * defaults below only guarantee a standalone create() does not crash.
 */
class ScreeningItemFactory extends Factory
{
    protected $model = ScreeningItem::class;

    public function definition(): array
    {
        return [
            'instrument_version_id' => fn () => InstrumentVersionFactory::new()->create()->id,
            'age_group_id' => fn () => AgeGroupFactory::new()->create()->id,
            'development_area_id' => DevelopmentArea::MG,
            'application_position_id' => ApplicationPosition::SUPINE,
            'code' => 'MG1.'.$this->faker->unique()->numberBetween(1, 999),
            'question' => $this->faker->sentence(),
            'sort_order' => 1,
            'legacy_id' => $this->faker->unique()->numberBetween(1, 100000),
        ];
    }
}
