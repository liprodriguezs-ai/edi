<?php

namespace Modules\Stimulation\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Stimulation\Models\BodyZone;
use Modules\Stimulation\Models\StimulationSession;
use Modules\Stimulation\Models\StimulationSessionRelaxationZone;

/**
 * @extends Factory<StimulationSessionRelaxationZone>
 */
class StimulationSessionRelaxationZoneFactory extends Factory
{
    protected $model = StimulationSessionRelaxationZone::class;

    public function definition(): array
    {
        return [
            'session_id' => StimulationSession::factory(),
            'body_zone_id' => BodyZone::factory(),
        ];
    }
}
