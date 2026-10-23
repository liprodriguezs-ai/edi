<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\ChildRecord\Models\Child;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Stimulation\Models\StimulationSession;

/**
 * @extends Factory<StimulationSession>
 */
class StimulationSessionFactory extends Factory
{
    protected $model = StimulationSession::class;

    public function definition(): array
    {
        return [
            'child_id' => fn () => Child::factory()->create()->id,
            'stimulation_group_id' => null,
            'visit_id' => null,
            'session_date' => $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'practitioner_id' => fn () => PractitionerProfile::factory()->create()->id,
        ];
    }

    /**
     * Builds a child with a default health center matching the project
     * fixture convention (see HealthCenterFactory::defaultMunicipality).
     */
    public function withDefaultChild(): static
    {
        return $this->state(fn (): array => [
            'child_id' => fn () => Child::factory()->create()->id,
        ]);
    }
}
