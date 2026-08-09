<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The 3 announcement catalogs are CENTRAL fixed-enum tables (shared rows with
 * stable UUID constants). Their names must therefore be globally unique —
 * nothing prevented duplicated catalog rows before this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcement_type', function (Blueprint $table): void {
            $table->unique('name');
        });

        Schema::table('announcement_priority', function (Blueprint $table): void {
            $table->unique('name');
        });

        Schema::table('announcement_status', function (Blueprint $table): void {
            $table->unique('name');
        });
    }

    /**
     * announcement_type is later converted to tenant-scoped by
     * 2026_06_11_120000_make_editable_catalogs_tenant_scoped, which drops this
     * global name unique. The rollback chain must therefore not assume the
     * constraint still exists — guard every drop with IF EXISTS (same pattern
     * that migration already uses) so migrate:refresh stays idempotent.
     */
    public function down(): void
    {
        foreach (['announcement_type', 'announcement_priority', 'announcement_status'] as $catalog) {
            DB::statement(sprintf('ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s_name_unique"', $catalog, $catalog));
            DB::statement(sprintf('DROP INDEX IF EXISTS "%s_name_unique"', $catalog));
        }
    }
};
