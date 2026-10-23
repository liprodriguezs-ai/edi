<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Instrument\Models\AreaScoringRule;
use Modules\Instrument\Models\DevelopmentArea;

/**
 * @extends Factory<AreaScoringRule>
 */
class AreaScoringRuleFactory extends Factory
{
    protected $model = AreaScoringRule::class;

    public function definition(): array
    {
        return [
            'instrument_version_id' => fn () => InstrumentVersionFactory::new()->create()->id,
            'age_group_id' => fn () => AgeGroupFactory::new()->create()->id,
            'development_area_id' => DevelopmentArea::MG,
            'item_count' => 2,
            'red_threshold' => 1,
            'green_threshold' => 0,
            'allows_yellow' => false,
            'source' => AreaScoringRule::SOURCE_LEGACY_JS,
            'notes' => null,
        ];
    }
}
