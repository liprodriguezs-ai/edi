<?php

namespace Modules\Municipality\Database\Seeders;

use JeroenZwart\CsvSeeder\CsvSeeder;
use Modules\Country\Models\Country;

class MunicipalityDatabaseSeeder extends CsvSeeder
{
    public function __construct()
    {
        $this->truncate = true;
        $this->foreignKeyCheck = false;

        $this->file = base_path('Modules/Municipality/database/seeders/csv/municipality.csv');
        $this->delimiter = ',';
        $this->tablename = 'municipality';
        $this->defaults = ['country_id' => Country::MEXICO];
    }

    public function run(): void
    {
        parent::run();
        // state_id is kept as a nullable plain uuid column (no FK) — state table was removed.
    }
}
