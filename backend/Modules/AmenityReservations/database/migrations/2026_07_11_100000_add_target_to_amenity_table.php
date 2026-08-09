<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds visibility-target columns to `amenity`: the module already shipped
 * (merged into main), so the original create migration
 * (2026_07_06_100004_create_amenity_table) stays untouched — append-only
 * history. This is a follow-up ALTER, mirroring
 * 2026_07_10_100000_drop_payment_timing_from_amenity_table's docblock
 * convention for the same reason.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('amenity', function (Blueprint $table): void {
            $table->string('target_type')->default('all')
                ->comment('WHO CAN SEE/RESERVE: TargetResolver vocabulary (all/by_type/by_tower/.../with_vehicles). NOT NULL, default all = zero behavior change on rollout.');
            $table->jsonb('target_selector')->nullable()
                ->comment('JSON payload that parameterises target_type (tower_ids/unit_feature_ids/etc.) — same shape as chat_channel.target_selector.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('amenity', function (Blueprint $table): void {
            $table->dropColumn(['target_type', 'target_selector']);
        });
    }
};
