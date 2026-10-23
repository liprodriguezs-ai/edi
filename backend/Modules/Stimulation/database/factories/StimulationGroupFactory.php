<?php

namespace Modules\Stimulation\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Models\StimulationGroupType;

/**
 * @extends Factory<StimulationGroup>
 */
class StimulationGroupFactory extends Factory
{
    protected $model = StimulationGroup::class;

    public function definition(): array
    {
        return [
            'name' => 'Grupo '.$this->faker->unique()->word(),
            'stimulation_group_type_id' => StimulationGroupType::factory(),
            'health_center_id' => HealthCenter::factory(),
            'promoter_user_id' => User::factory(),
            'start_date' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'end_date' => null,
            'is_active' => true,
        ];
    }
}
