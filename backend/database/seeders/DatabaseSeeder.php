<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Modules\Country\Models\Country;
use Modules\Municipality\Database\Seeders\MunicipalityDatabaseSeeder;
use Modules\SubscriptionPlan\Database\Seeders\PlanModuleMatrixSeeder;
use Modules\SubscriptionPlan\Database\Seeders\PlanQuotaSeeder;
use Modules\Tenant\Database\Seeders\TenantDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Artisan::call('cache:clear');

        // --- Central DB (tablas en BD central) ---
        Country::firstOrCreate(
            ['id' => Country::MEXICO],
            ['id' => Country::MEXICO, 'name' => 'Mexico', 'code' => 'MX', 'phone_code' => '+52']
        );

        $this->call(MaritalStatusTableSeeder::class);
        $this->call(MunicipalityDatabaseSeeder::class);
        $this->call(CatalogSeeder::class);

        $this->call(ModuleTableSeeder::class);
        $this->call(SystemTableSeeder::class);
        $this->call(PlanModuleMatrixSeeder::class);
        $this->call(PlanQuotaSeeder::class);
        $this->call(PermissionsTableSeeder::class);
        $this->call(RolesTableSeeder::class);
        $this->call(UsersTableSeeder::class);
        $this->call(RolePermissionTableSeeder::class);
        $this->call(UserPermissionTableSeeder::class);
        $this->call(TenantDatabaseSeeder::class);
    }
}
