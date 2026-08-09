<?php

namespace Modules\Tenant\Database\Seeders;

use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Database\Seeder;
use Modules\AmenityReservations\Database\Seeders\AmenityReservationsDatabaseSeeder;
use Modules\Chat\Services\ChatService;
use Modules\Collections\Database\Seeders\CollectionsDatabaseSeeder;
use Modules\Configuration\Database\Seeders\NotificationTemplateSeeder;
use Modules\Notification\Database\Seeders\TenantNotificationSettingSeeder;
use Modules\Treasury\Models\ExpenseCategory;

class TenantDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds only genuinely per-tenant data: the 12 self-administrable catalogs
     * (TenantCatalogSeeder), expense categories, system chat channels and
     * notification templates. Central fixed-enum catalogs (FSM/behavior) are
     * seeded once by the central CatalogSeeder (called from DatabaseSeeder).
     */
    public function run(): void
    {
        if (tenancy()->initialized) {
            $this->call(TenantCatalogSeeder::class);
            $this->seedExpenseCategories();
            app(ChatService::class)->createSystemChannels();
            $this->call(NotificationTemplateSeeder::class);
            $this->call(CollectionsDatabaseSeeder::class);
            $this->call(TenantNotificationSettingSeeder::class);
            $this->call(AmenityReservationsDatabaseSeeder::class);
        }
    }

    /**
     * Seed default expense categories for the current tenant.
     *
     * ExpenseCategory is tenant-scoped (has tenant_id via BelongsToTenant),
     * so each tenant gets its own default set on first boot.
     */
    private function seedExpenseCategories(): void
    {
        $categories = [
            ['name' => 'Salarios', 'order' => 0, 'is_active' => true],
            ['name' => 'Servicios públicos', 'order' => 1, 'is_active' => true],
            ['name' => 'Mantenimiento', 'order' => 2, 'is_active' => true],
            ['name' => 'Insumos', 'order' => 3, 'is_active' => true],
            ['name' => 'Bancarias', 'order' => 4, 'is_active' => true],
            ['name' => 'Imprevistos', 'order' => 5, 'is_active' => true],
            ['name' => 'Proyectos', 'order' => 6, 'is_active' => true],
        ];

        foreach ($categories as $category) {
            ExpenseCategory::updateOrCreate(
                ['name' => $category['name'], 'parent_id' => null],
                $category
            );
        }
    }
}
