<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * CENTRAL catalog (S5): the classifier branches on these fixed keys, so it
     * is central and read-only for tenant roles, seeded by NutritionSeeder.
     */
    public function up(): void
    {
        Schema::create('nutritional_status', function (Blueprint $table) {
            $table->comment('Central catalog of nutritional status bands (P/T, P/E, T/E) plus "no reference"');
            BaseModel::columns($table, false);
            $table->string('key')->comment("Machine-readable slug ('severe_malnutrition' ... 'tall_stature', 'no_reference').");
            $table->string('name')->comment('Spanish label shown in the UI and reports.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX nutritional_status_key_unique ON nutritional_status (key) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS nutritional_status_key_unique');
        Schema::dropIfExists('nutritional_status');
    }
};
