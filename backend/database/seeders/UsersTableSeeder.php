<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use QCH\Core\Models\Role;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        if (! User::where('email', 'superadmin@company.web')->exists()) {

            $user = User::create([
                'email' => 'superadmin@company.web',
                'name' => 'Admin',
                // 'last_primary_name' => 'Admin',
                // 'category_id' => StudentCategory::inRandomOrder()->first()->id,
                // 'last_second_name' => 'Admin',
                'password' => bcrypt('123abc'),
                // 'email_verified' => 1,
            ]);

            $user->assignRole(Role::SLUG_SUPER_ADMIN);
        }
    }
}
