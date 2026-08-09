<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionPlan\Database\Seeders\PlanModuleMatrixSeeder;

class ResetPermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        Model::unguard();
        $this->call(ModuleTableSeeder::class);
        $this->call(SystemTableSeeder::class);
        $this->call(PermissionsTableSeeder::class);
        $this->call(RolesTableSeeder::class);
        $this->call(RolePermissionTableSeeder::class);
        // $this->call(RoleUserTableSeeder::class);
        $this->call(UserPermissionTableSeeder::class);

        // ModuleTableSeeder trunca `module` y el FK cascadeOnDelete de
        // billable_module_system_module borra el mapeo de billing v2 en
        // silencio: sin re-sembrar la matriz, el próximo recompute() de
        // entitlements desadjuntaría TODOS los sistemas de TODOS los tenants.
        // Solo se restaura cuando la matriz YA existe (BD viva con el pivot
        // arrastrado por el cascade); una BD fresca la siembra DatabaseSeeder y
        // los tests que solo resetean permisos no deben ganar filas de billing.
        // Tras este seeder, corre `php artisan billing:recompute-entitlements`
        // para re-proyectar el acceso de los tenants existentes.
        if (DB::table('billable_module')->exists()) {
            $this->call(PlanModuleMatrixSeeder::class);
        }

        Model::reguard();
    }
}
