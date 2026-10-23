<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Instrument\Models\ResultColor;
use Modules\Instrument\Models\ScreeningItemOption;

/**
 * @extends Factory<ScreeningItemOption>
 */
class ScreeningItemOptionFactory extends Factory
{
    protected $model = ScreeningItemOption::class;

    public function definition(): array
    {
        return [
            'instrument_version_id' => fn () => InstrumentVersionFactory::new()->create()->id,
            'screening_item_id' => fn () => ScreeningItemFactory::new()->create()->id,
            'label' => 'NO',
            'sort_order' => 1,
            'result_color_id' => ResultColor::RED,
        ];
    }
}
