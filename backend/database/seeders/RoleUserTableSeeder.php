<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class RoleUserTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $users = User::all();
        foreach ($users as $key => $user) {
            $hasChild = User::where('parent_id', $user->id)->count();
        }
    }
}
