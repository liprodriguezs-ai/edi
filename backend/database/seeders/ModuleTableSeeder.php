<?php

namespace Database\Seeders;

use JeroenZwart\CsvSeeder\CsvSeeder;
use Modules\Module\Models\Module;

class ModuleTableSeeder extends CsvSeeder
{
    public function __construct()
    {
        $this->truncate = true;
        $this->foreignKeyCheck = true;

        $this->file = base_path('database/seeders/csv/modules.csv');
        $this->delimiter = ',';
        $this->tablename = 'module';
        $this->write_logs = true;
        $this->insert_callback = function ($chunk) {
            foreach ($chunk as $row) {
                $item = $row->toArray();
                Module::create($item);
            }
        };
    }

    /**
     * Run the database seeds.
     */
    public function run()
    {
        parent::run();
    }
}
