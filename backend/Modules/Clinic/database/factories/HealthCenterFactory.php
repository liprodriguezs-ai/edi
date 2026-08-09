<?php

namespace Modules\Clinic\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Clinic\Models\HealthCenter;
use Modules\Country\Models\Country;
use Modules\Municipality\Models\Municipality;

/**
 * @extends Factory<HealthCenter>
 */
class HealthCenterFactory extends Factory
{
    protected $model = HealthCenter::class;

    public function definition(): array
    {
        return [
            'name' => 'Centro de Salud '.$this->faker->unique()->city(),
            'clues_code' => strtoupper($this->faker->bothify('?????######')),
            'municipality_id' => fn () => self::defaultMunicipality()->id,
            'address' => $this->faker->address(),
            'is_active' => true,
        ];
    }

    /**
     * Municipality/Country have no factories of their own (central catalogs
     * seeded from a fixed dataset in production). Tests get a single
     * deterministic municipality via firstOrCreate — no dependency on the
     * production dataset being loaded.
     */
    public static function defaultMunicipality(): Municipality
    {
        $country = Country::firstOrCreate(
            ['id' => Country::MEXICO],
            ['name' => 'México', 'code' => 'MX', 'phone_code' => '+52']
        );

        return Municipality::firstOrCreate(
            ['code' => '001', 'state_code' => 'DF'],
            ['name' => 'Municipio de prueba', 'country_id' => $country->id]
        );
    }
}
