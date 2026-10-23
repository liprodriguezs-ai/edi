<?php

namespace Database\Seeders;

use Illuminate\Support\Str;
use JeroenZwart\CsvSeeder\CsvSeeder;

class PermissionsTableSeeder extends CsvSeeder
{
    public function __construct()
    {
        $this->truncate = true;
        $this->foreignKeyCheck = true;
        $this->file = base_path('database/seeders/csv/permissions.csv');
        $this->parsers = ['uuid' => function () {
            return Str::uuid7();
        }];
        $this->delimiter = ',';
        $this->table = 'permissions';
        $this->console_logs = true;
        $this->write_logs = true;
    }

    /**
     * Run the database seeds.
     */
    public function run()
    {
        parent::run();
    }
}
