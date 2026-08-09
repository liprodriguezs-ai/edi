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
        Schema::table('media', function (Blueprint $table) {
            // Elimina la clave foránea si existe
            $table->dropIndex(['model_type', 'model_id']);
            $table->dropColumn(['model_type', 'model_id']);
        });
        // Luego crea las nuevas columnas con tipo UUID
        Schema::table('media', function (Blueprint $table) {
            $table->uuidMorphs('model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Primero elimina las columnas UUID
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['model_type', 'model_id']);
            $table->dropColumn(['model_type', 'model_id']);
        });

        // Luego restaura las columnas a bigint
        Schema::table('media', function (Blueprint $table) {
            $table->morphs('model');
        });
    }
};
