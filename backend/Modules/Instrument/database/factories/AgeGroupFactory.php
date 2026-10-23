<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\InstrumentVersion;

/**
 * @extends Factory<AgeGroup>
 */
class AgeGroupFactory extends Factory
{
    protected $model = AgeGroup::class;

    public function definition(): array
    {
        $ordinal = $this->faker->unique()->numberBetween(1, 15);

        return [
            'instrument_version_id' => fn () => InstrumentVersion::factory()->create()->id,
            'code' => "G{$ordinal}",
            'name' => "GRUPO {$ordinal}",
            'ordinal' => $ordinal,
            'is_alternate' => false,
            'min_age_days' => ($ordinal - 1) * 30 + 30,
            'max_age_days' => $ordinal * 30 + 29,
            'min_age_months' => $ordinal,
            'max_age_months' => $ordinal,
            'alternate_age_group_id' => null,
            'materials_note' => null,
            'legacy_id' => $ordinal,
        ];
    }

    public function alternate(): self
    {
        return $this->state(fn (): array => [
            'code' => 'G'.$this->faker->unique()->numberBetween(1, 14).'A',
            'name' => 'GRUPO ALTERNO',
            'is_alternate' => true,
            'min_age_days' => null,
            'max_age_days' => null,
            'min_age_months' => null,
            'max_age_months' => null,
        ]);
    }
}
