<?php

namespace Modules\Company\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Company\Models\Company;

class CompanyDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Company::firstOrCreate([
            'name' => 'Financiera',
        ], [
            'name' => 'Financiera',
            'slug' => 'financiera',
        ]);
        // $this->call([]);
    }
}
