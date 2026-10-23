<?php

namespace Modules\Instrument\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Instrument\Models\GlobalResultMessage;
use Modules\Instrument\Models\ResultColor;

/**
 * @extends Factory<GlobalResultMessage>
 */
class GlobalResultMessageFactory extends Factory
{
    protected $model = GlobalResultMessage::class;

    public function definition(): array
    {
        return [
            'instrument_version_id' => fn () => InstrumentVersionFactory::new()->create()->id,
            'key' => GlobalResultMessage::KEY_GREEN,
            'result_color_id' => ResultColor::GREEN,
            'headline' => 'Desarrollo normal',
            'referral_items' => ['Continuar con sesiones de interacción oportuna.'],
            'sort_order' => 1,
        ];
    }
}
