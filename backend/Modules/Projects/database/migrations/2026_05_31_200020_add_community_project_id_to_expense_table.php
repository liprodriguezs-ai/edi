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
        // The column already exists (added in Treasury without FK).
        // We add the FK constraint now that community_project table exists.
        if (Schema::hasColumn('expense', 'community_project_id')) {
            Schema::table('expense', function (Blueprint $table) {
                $table->foreign('community_project_id')
                    ->references('id')
                    ->on('community_project')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expense', 'community_project_id')) {
            Schema::table('expense', function (Blueprint $table) {
                $table->dropForeign(['community_project_id']);
            });
        }
    }
};
