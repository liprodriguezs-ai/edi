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
        Schema::table('module_system', function (Blueprint $table) {
            $table->string('group_label')->nullable()->after('sort_order')->comment('Display label for the sidebar group this module belongs to');
            $table->string('group_icon')->nullable()->after('group_label')->comment('Icon name for the sidebar group header');
        });
    }

    public function down(): void
    {
        Schema::table('module_system', function (Blueprint $table) {
            $table->dropColumn(['group_label', 'group_icon']);
        });
    }
};
