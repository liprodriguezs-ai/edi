<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {

            $table->string('nss')->nullable()->comment('Mexican social security number (NSS)');
        });
    }

    public function down(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->dropColumn('nss');
        });
    }
};
