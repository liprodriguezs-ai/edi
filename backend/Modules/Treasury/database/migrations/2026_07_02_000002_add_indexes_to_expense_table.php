<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The financial-dashboard aggregation endpoints run several queries filtering
 * and grouping by paid_at, expense_status_id, provider_id, and
 * expense_category_id — Postgres does not index FK columns automatically, so
 * without these the hottest dashboard queries degrade to a tenant_id scan
 * filtered in-memory. Mirrors the indexes already present on the sibling
 * `payment` table for the identical query shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense', function (Blueprint $table): void {
            $table->index(['tenant_id', 'paid_at']);
            $table->index('expense_status_id');
            $table->index('provider_id');
            $table->index('expense_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('expense', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'paid_at']);
            $table->dropIndex(['expense_status_id']);
            $table->dropIndex(['provider_id']);
            $table->dropIndex(['expense_category_id']);
        });
    }
};
