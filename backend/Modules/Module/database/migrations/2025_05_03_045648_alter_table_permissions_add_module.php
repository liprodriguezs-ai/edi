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
        Schema::table('permissions', function (Blueprint $table) {
            if (! Schema::hasColumn('permissions', 'module_id')) {
                $table->foreignUuid('module_id')->nullable()->references('id')->on('module')->comment('FK to module; groups this Spatie permission under its owning module');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // check if exist table
        if (Schema::hasTable('permissions')) {
            if (Schema::hasColumn('permissions', 'module_id')) {
                Schema::table('permissions', function (Blueprint $table) {
                    $table->dropForeign(['module_id']);
                    $table->dropColumn('module_id');
                });
            }
        }
    }
};
