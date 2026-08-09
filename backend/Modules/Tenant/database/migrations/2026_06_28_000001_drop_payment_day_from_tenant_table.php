<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('payment_day');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->unsignedTinyInteger('payment_day')->nullable()->after('status')->comment('Day of the month (1–31) on which the cluster expects to process payments.');
        });
    }
};
