<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('complaint_category', function (Blueprint $table) {
            $table->comment('Catálogo de categorías de denuncia del clúster — editable por el admin');
            BaseModel::columns($table, true);
            $table->string('name', 100)->comment('Nombre de la categoría de denuncia');
            $table->boolean('is_active')->default(true)->comment('Si la categoría está disponible para selección');
            $table->integer('order')->default(0)->comment('Orden de aparición en el listado');
        });

        // Soft-delete-aware unique: a soft-deleted category must not keep reserving its name.
        DB::statement(
            'CREATE UNIQUE INDEX complaint_category_name_tenant_unique ON complaint_category (tenant_id, name) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS complaint_category_name_tenant_unique');
        Schema::dropIfExists('complaint_category');
    }
};
