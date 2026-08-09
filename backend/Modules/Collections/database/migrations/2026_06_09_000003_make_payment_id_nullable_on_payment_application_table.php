<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Credit balance auto-application (Flujo C.5, spec §8.7.3.4): when newly
     * generated charges consume a unit's credit balance there is NO payment row
     * behind the application — payment_id NULL + source=credit identifies these
     * system-generated applications in the ledger.
     */
    public function up(): void
    {
        Schema::table('payment_application', function (Blueprint $table) {
            $table->uuid('payment_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_application', function (Blueprint $table) {
            $table->uuid('payment_id')->nullable(false)->change();
        });
    }
};
