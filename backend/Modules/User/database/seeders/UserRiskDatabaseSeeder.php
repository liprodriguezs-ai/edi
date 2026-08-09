<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\UserRisk;

class UserRiskDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        UserRisk::updateOrCreate([
            'id' => UserRisk::LOW,
        ], [
            'name' => 'Bajo',
        ]);
        UserRisk::updateOrCreate([
            'id' => UserRisk::MEDIUM,
        ], [
            'name' => 'Medio',
        ]);
        UserRisk::updateOrCreate([
            'id' => UserRisk::HIGH,
        ], [
            'name' => 'Alto',
        ]);
    }
}
