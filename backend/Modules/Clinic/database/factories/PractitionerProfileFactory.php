<?php

namespace Modules\Clinic\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;

/**
 * @extends Factory<PractitionerProfile>
 */
class PractitionerProfileFactory extends Factory
{
    protected $model = PractitionerProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'license_number' => (string) $this->faker->unique()->numerify('########'),
            'specialty' => $this->faker->randomElement(['Pediatría', 'Enfermería', 'Psicología', 'Medicina General']),
            'health_center_id' => HealthCenter::factory(),
        ];
    }
}
