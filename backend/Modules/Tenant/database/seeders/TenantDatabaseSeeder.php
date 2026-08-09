<?php

namespace Modules\Tenant\Database\Seeders;

use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Database\Seeder;
use Modules\Notification\Database\Seeders\TenantNotificationSettingSeeder;

class TenantDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds only genuinely per-tenant data: the self-administrable catalogs
     * (TenantCatalogSeeder) and the per-tenant notification settings. Central
     * fixed-enum catalogs (FSM/behavior) are seeded once by the central
     * CatalogSeeder (called from DatabaseSeeder).
     *
     * The template also seeded expense categories, system chat channels,
     * notification templates and the collections/amenities defaults. Those
     * modules belonged to the condominium domain and are gone; the EDI
     * equivalents are registered here as their modules are built.
     */
    public function run(): void
    {
        if (tenancy()->initialized) {
            $this->call(TenantCatalogSeeder::class);
            $this->call(TenantNotificationSettingSeeder::class);
        }
    }
}
