<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\VisitorStatus;

return new class extends Migration
{
    /**
     * §14.4.4–14.4.5 remote approval flow statuses. visitor_status is a CENTRAL
     * catalog (UUID-keyed, no tenant_id) seeded by CatalogSeeder; the new rows are
     * inserted here idempotently so every environment gains them on migrate without
     * touching the root seeders.
     */
    public function up(): void
    {
        $rows = [
            ['id' => VisitorStatus::PENDING_APPROVAL, 'name' => 'Pendiente de aprobación', 'color' => '#F59E0B', 'order' => 4],
            ['id' => VisitorStatus::APPROVED, 'name' => 'Aprobado', 'color' => '#3B82F6', 'order' => 5],
            ['id' => VisitorStatus::REJECTED, 'name' => 'Rechazado', 'color' => '#DC2626', 'order' => 6],
        ];

        foreach ($rows as $row) {
            DB::table('visitor_status')->updateOrInsert(
                ['id' => $row['id']],
                [
                    'name' => $row['name'],
                    'color' => $row['color'],
                    'order' => $row['order'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('visitor_status')->whereIn('id', [
            VisitorStatus::PENDING_APPROVAL,
            VisitorStatus::APPROVED,
            VisitorStatus::REJECTED,
        ])->delete();
    }
};
