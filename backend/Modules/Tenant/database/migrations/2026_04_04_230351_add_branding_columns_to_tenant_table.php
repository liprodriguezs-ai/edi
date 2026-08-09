<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('brand_color_primary', 9)->nullable()->comment('Primary brand colour as a hex code (e.g. #FF5733).');
            $table->string('brand_color_secondary', 9)->nullable()->comment('Secondary brand colour as a hex code.');
            $table->string('brand_color_background', 9)->nullable()->comment('Background brand colour as a hex code.');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['brand_color_primary', 'brand_color_secondary', 'brand_color_background']);
        });
    }
};
