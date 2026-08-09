<?php

namespace Modules\ChildRecord\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\CaregiverRelationship;

/**
 * @extends Factory<CaregiverRelationship>
 */
class CaregiverRelationshipFactory extends Factory
{
    protected $model = CaregiverRelationship::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
