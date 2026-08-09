<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('gamification', function (Blueprint $table) {
            if (Schema::hasColumn('gamification', 'points_accumulated')) {
                $table->dropColumn('points_accumulated');
            }

            if (Schema::hasColumn('gamification', 'points_monthly')) {
                $table->dropColumn('points_monthly');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('gamification', function (Blueprint $table) {
            if (Schema::hasColumn('gamification', 'points_accumulated')) {
                $table->integer('points_accumulated')->default(0);
            }

            if (Schema::hasColumn('gamification', 'points_monthly')) {
                $table->integer('points_monthly')->default(0);
            }
        });
    }
};
