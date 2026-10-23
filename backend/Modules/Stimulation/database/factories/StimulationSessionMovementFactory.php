<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stimulation\Models\BodyZone;
use Modules\Stimulation\Models\MassageMovement;
use Modules\Stimulation\Models\StimulationSession;
use Modules\Stimulation\Models\StimulationSessionMovement;

/**
 * @extends Factory<StimulationSessionMovement>
 */
class StimulationSessionMovementFactory extends Factory
{
    protected $model = StimulationSessionMovement::class;

    public function definition(): array
    {
        return [
            'session_id' => StimulationSession::factory(),
            'body_zone_id' => BodyZone::factory(),
            'massage_movement_id' => MassageMovement::factory(),
        ];
    }
}
