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
        Schema::create('package_type', function (Blueprint $table) {
            $table->comment('Catálogo de tipos de paquete del clúster — editable por el admin');
            BaseModel::columns($table, true);
            $table->string('name', 100)->comment('Nombre del tipo de paquete');
            $table->boolean('is_active')->default(true)->comment('Si el tipo está disponible para selección');
            $table->integer('order')->default(0)->comment('Orden de aparición en el listado');
        });

        // Soft-delete-aware unique: a soft-deleted type must not keep reserving its name.
        DB::statement(
            'CREATE UNIQUE INDEX pkg_type_name_tenant_unique ON package_type (tenant_id, name) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pkg_type_name_tenant_unique');
        Schema::dropIfExists('package_type');
    }
};
