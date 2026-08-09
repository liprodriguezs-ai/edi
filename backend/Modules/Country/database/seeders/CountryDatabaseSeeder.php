<?php

namespace Modules\Country\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Country\Models\Country;

class CountryDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // $this->call([]);
        $country = Country::create([
            'id' => Country::MEXICO,
            'name' => 'Mexico',
            'code' => 'MX',
            'phone_code' => '+52',
            'currency' => 'MXN',
            'currency_symbol' => '$',
        ]);
    }
}
