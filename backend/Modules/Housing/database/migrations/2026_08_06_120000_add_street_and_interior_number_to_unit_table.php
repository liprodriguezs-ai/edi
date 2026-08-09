<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit', function (Blueprint $table) {
            $table->string('street', 150)->nullable()->after('number')->comment('Street name the unit fronts inside the cluster (Mexican address: calle)');
            $table->string('interior_number', 50)->nullable()->after('street')->comment('Interior number of the unit (Mexican address: número interior) — complements `number` as the exterior one');
        });
    }

    public function down(): void
    {
        Schema::table('unit', function (Blueprint $table) {
            $table->dropColumn(['street', 'interior_number']);
        });
    }
};
