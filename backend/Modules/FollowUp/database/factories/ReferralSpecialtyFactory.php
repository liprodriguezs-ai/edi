<?php

namespace Modules\FollowUp\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\FollowUp\Models\ReferralSpecialty;

/**
 * @extends Factory<ReferralSpecialty>
 */
class ReferralSpecialtyFactory extends Factory
{
    protected $model = ReferralSpecialty::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
            'is_active' => true,
        ];
    }
}
