<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Instrument\Models\GlobalScoringRule;
use Modules\Instrument\Models\ResultColor;

/**
 * @extends Factory<GlobalScoringRule>
 */
class GlobalScoringRuleFactory extends Factory
{
    protected $model = GlobalScoringRule::class;

    public function definition(): array
    {
        return [
            'instrument_version_id' => fn () => InstrumentVersionFactory::new()->create()->id,
            'age_group_id' => fn () => AgeGroupFactory::new()->create()->id,
            'sequence' => 90,
            'condition' => ['all' => []],
            'result_color_id' => ResultColor::GREEN,
            'global_result_message_id' => fn () => GlobalResultMessageFactory::new()->create()->id,
            'source' => GlobalScoringRule::SOURCE_MANUAL,
            'notes' => null,
        ];
    }
}
