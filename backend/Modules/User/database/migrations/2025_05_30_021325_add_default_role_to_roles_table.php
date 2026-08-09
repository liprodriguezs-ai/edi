<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\User\Models\User;
use QCH\Core\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /* Schema::table('user', function (Blueprint $table) {
            //crea un user con su rol
            if (!User::where('email', 'kiosk@company.web')->exists() && Company::first()) {
                $user = User::create([
                    'name' => 'Kiosk Admin',
                    'email' => 'kiosk@company.web',
                    'password' => bcrypt('123abc'),
                    'tenant_id' => Company::first()->id,
                ]);
                $user->assignRole(Role::KIOSK_ADMIN);
            }
        });*/
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            if (User::where('email', 'kiosk@company.web')->exists()) {
                $user = User::where('email', 'kiosk@company.web')->first();
                $user->removeRole(Role::KIOSK_ADMIN);
                $user->delete();
            }
        });
    }
};
