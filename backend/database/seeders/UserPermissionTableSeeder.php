<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use QCH\Core\Models\Role;

class UserPermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {

        $users = User::where('email', 'superadmin@company.web')->get();
        foreach ($users as $key => $user) {

            $roles = $user->roles;
            if (! isset($roles[0])) {
                if ($user->email == 'superadmin@company.web') {
                    $user->assignRole(Role::SLUG_SUPER_ADMIN);
                }
                $roles = $user->roles;
            }
        }
    }
}
