<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\User\Models\UserRegisterStatus;

class UserRegisterStatusDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        UserRegisterStatus::updateOrCreate([
            'id' => UserRegisterStatus::INCOMPLETE,
        ], [
            'name' => 'Incompleto',
            'color' => 'outline',
        ]);
        UserRegisterStatus::updateOrCreate([
            'id' => UserRegisterStatus::PENDING,
        ], [
            'name' => 'Pendiente de autorizar',
            'color' => 'secondary',
        ]);
        UserRegisterStatus::updateOrCreate([
            'id' => UserRegisterStatus::APPROVED,
        ], [
            'name' => 'Aprobado',
            'color' => 'success',
        ]);
        UserRegisterStatus::updateOrCreate([
            'id' => UserRegisterStatus::REJECTED,
        ], [
            'name' => 'Rechazado',
            'color' => 'destructive',
        ]);
    }
}
