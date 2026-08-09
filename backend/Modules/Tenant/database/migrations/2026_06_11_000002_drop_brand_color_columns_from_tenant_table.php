<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['brand_color_primary', 'brand_color_secondary', 'brand_color_background']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('brand_color_primary', 9)->nullable();
            $table->string('brand_color_secondary', 9)->nullable();
            $table->string('brand_color_background', 9)->nullable();
        });
    }
};
