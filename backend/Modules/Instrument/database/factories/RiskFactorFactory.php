<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Instrument\Models\RiskFactor;

/**
 * @extends Factory<RiskFactor>
 */
class RiskFactorFactory extends Factory
{
    protected $model = RiskFactor::class;

    public function definition(): array
    {
        return [
            'instrument_version_id' => fn () => InstrumentVersionFactory::new()->create()->id,
            'code' => 'FRB'.$this->faker->unique()->numberBetween(1, 7),
            'question' => $this->faker->sentence(),
            'sort_order' => 1,
            'auto_derivation_key' => null,
            'legacy_id' => $this->faker->unique()->numberBetween(1, 100),
        ];
    }
}
