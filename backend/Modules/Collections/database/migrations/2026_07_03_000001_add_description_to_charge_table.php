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
        Schema::table('charge', function (Blueprint $table) {
            $table->string('description')->nullable()->after('charge_concept_id')
                ->comment('Free-text motive for ad-hoc direct charges (e.g. the "Otro" concept); null for batch/catalog charges.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
