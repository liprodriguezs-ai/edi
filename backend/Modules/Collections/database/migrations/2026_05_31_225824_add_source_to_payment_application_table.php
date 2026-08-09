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
        Schema::table('payment_application', function (Blueprint $table) {
            $table->string('source')
                ->default('cash')
                ->after('applied_amount')
                ->comment('Funding source of this application: cash (payment cash) | credit (consumed pre-existing credit).');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_application', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
