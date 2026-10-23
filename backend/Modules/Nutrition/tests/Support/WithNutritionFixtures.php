<?php

declare(strict_types=1);

namespace Modules\Nutrition\Tests\Support;

use Database\Seeders\CatalogSeeder;
use Database\Seeders\ModuleTableSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolePermissionTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\SystemTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Sex;
use Modules\ChildRecord\Models\Visit;
use Modules\Nutrition\Database\Seeders\NutritionSeeder;
use Modules\Screening\Tests\Support\WithScreeningFixtures;
use Tests\Support\WithTenantSetup;

/**
 * Nutrition fixtures: the platform registry WITHOUT the (slow, ~13 s)
 * instrument seed -- nutrition never needs the item bank -- plus the WHO
 * reference tables.
 */
trait WithNutritionFixtures
{
    use WithScreeningFixtures;
    use WithTenantSetup;

    protected function seedNutritionPlatform(): void
    {
        (new CatalogSeeder)->run();
        (new NutritionSeeder)->run();
        Artisan::call('db:seed', ['--class' => ModuleTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => SystemTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => PermissionsTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolesTableSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => RolePermissionTableSeeder::class, '--force' => true]);
    }

    /**
     * A male child born 2023-01-10 with a visit on 2025-01-10 (exactly 24
     * completed months). Must run inside the tenant.
     *
     * @return array{child: Child, visit: Visit}
     */
    protected function makeMaleChildAt24Months(): array
    {
        $fx = $this->makeChildVisit(730, null, ['sex_id' => Sex::MALE, 'birth_date' => '2023-01-10']);
        $fx['visit']->forceFill(['visit_date' => '2025-01-10'])->save();

        return ['child' => $fx['child'], 'visit' => $fx['visit']];
    }
}
