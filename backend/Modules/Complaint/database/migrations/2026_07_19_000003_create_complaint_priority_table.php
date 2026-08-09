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
        Schema::create('complaint_priority', function (Blueprint $table) {
            $table->comment('Catálogo de prioridades de denuncia del clúster — cosmético, sin ramificación de código');
            BaseModel::columns($table, true);
            $table->string('name', 100)->comment('Nombre de la prioridad de denuncia');
            $table->string('color', 20)->nullable()->comment('Color hex para badge de UI');
            $table->boolean('is_active')->default(true)->comment('Si la prioridad está disponible para selección');
            $table->integer('order')->default(0)->comment('Orden de aparición en el listado');
        });

        // Soft-delete-aware unique: a soft-deleted priority must not keep reserving its name.
        DB::statement(
            'CREATE UNIQUE INDEX complaint_priority_name_tenant_unique ON complaint_priority (tenant_id, name) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS complaint_priority_name_tenant_unique');
        Schema::dropIfExists('complaint_priority');
    }
};
