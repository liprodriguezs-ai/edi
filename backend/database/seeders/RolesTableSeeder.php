<?php

namespace Database\Seeders;

use JeroenZwart\CsvSeeder\CsvSeeder;
use QCH\Core\Models\Role;

class RolesTableSeeder extends CsvSeeder
{
    public function __construct()
    {

        $this->truncate = true;
        $this->foreignKeyCheck = true;

        $this->file = base_path('database/seeders/csv/roles.csv');
        $this->delimiter = ',';
        $this->tablename = 'roles';
        $this->parser = ['system' => function ($value) {
            return $value == '1' ? true : false;
        }];

        /*$this->insert_callback = function ($chunk) {
            foreach ($chunk as $row) {
                $item = $row->only(['id','name','slug','description','system'])->toArray();
                Role::create($item);
            }
        };*/
    }

    /**
     * Run the database seeds.
     */
    public function run()
    {
        parent::run();
    }
}
