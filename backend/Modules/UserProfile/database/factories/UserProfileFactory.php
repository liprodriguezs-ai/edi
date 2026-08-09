<?php

namespace Modules\UserProfile\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\UserProfile\Models\UserProfile;

/**
 * @extends Factory<UserProfile>
 */
class UserProfileFactory extends Factory
{
    protected $model = UserProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'rfc' => strtoupper($this->faker->bothify('???######???')),
            'curp' => strtoupper($this->faker->bothify('????######????????')),
            'birthdate' => $this->faker->date(),
            'address' => $this->faker->address(),
            'emergency_contact_name' => $this->faker->name(),
            'emergency_contact_phone' => $this->faker->phoneNumber(),
            'notes' => $this->faker->optional()->sentence(),
        ];
    }
}
