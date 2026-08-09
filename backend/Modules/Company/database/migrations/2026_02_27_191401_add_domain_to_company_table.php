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
        Schema::table('company', function (Blueprint $table) {
            if (! Schema::hasColumn('company', 'domain')) {
                $table->string('domain')->nullable()->comment('Custom domain or subdomain associated with the company cluster.');
            }
            // quitamos favicon y logo
            if (Schema::hasColumn('company', 'favicon')) {
                $table->dropColumn('favicon');
            }
            if (Schema::hasColumn('company', 'logo')) {
                $table->dropColumn('logo');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn('domain');
            if (Schema::hasColumn('company', 'favicon')) {
                $table->string('favicon')->nullable();
            }
            if (Schema::hasColumn('company', 'logo')) {
                $table->string('logo')->nullable();
            }
        });
    }
};
