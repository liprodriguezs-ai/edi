<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('status')->default('activo')->after('name')->comment('Organization account status: active, pending_payment, or blocked.');
            $table->unsignedTinyInteger('payment_day')->nullable()->after('status')->comment('Day of the month (1–31) on which the organization expects to process payments.');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['status', 'payment_day']);
        });
    }
};
