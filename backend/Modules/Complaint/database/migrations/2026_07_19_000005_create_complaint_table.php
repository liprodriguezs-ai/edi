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
        Schema::create('complaint', function (Blueprint $table) {
            $table->comment('Denuncias registradas por la mesa directiva del clúster');
            BaseModel::columns($table, true);

            $table->string('case_number', 14)->comment('Folio secuencial D-YYYY-NNNNNN; único por tenant');
            $table->string('title', 150)->comment('Título breve legible para listados');
            $table->foreignUuid('complaint_category_id')->references('id')->on('complaint_category')->restrictOnDelete()->comment('FK categoría tenant-scoped de la denuncia; clasificación, sin ramificación');
            $table->foreignUuid('complaint_priority_id')->references('id')->on('complaint_priority')->restrictOnDelete()->comment('FK prioridad tenant-scoped de la denuncia; cosmética, sin ramificación');
            $table->foreignUuid('complaint_status_id')->references('id')->on('complaint_status')->restrictOnDelete()->comment('FK estado FSM central de la denuncia; gestionado solo por ComplaintService');
            $table->foreignUuid('reporter_user_id')->nullable()->references('id')->on('user')->nullOnDelete()->comment('FK usuario que presentó la denuncia; estampado por el servicio desde el auth user');
            $table->foreignUuid('assigned_user_id')->nullable()->references('id')->on('user')->nullOnDelete()->comment('FK usuario gestor responsable asignado a la denuncia');
            $table->foreignUuid('against_unit_id')->nullable()->references('id')->on('unit')->nullOnDelete()->comment('FK unidad denunciada, opcional; dispara la exclusión automática de confidencialidad');
            $table->text('against_description')->comment('Descripción libre siempre presente del sujeto/incidente denunciado');
            $table->dateTime('resolved_at')->nullable()->comment('Timestamp de entrada a estado terminal (resolved/dismissed); se limpia al reabrir');

            $table->index('complaint_category_id');
            $table->index('complaint_priority_id');
            $table->index('against_unit_id');
            $table->index('assigned_user_id');
            $table->index('reporter_user_id');
            // Composite: the board list default sort filters/orders by status and creation date.
            $table->index(['complaint_status_id', 'created_at'], 'complaint_status_created_idx');
        });

        // Partial unique index on (tenant_id, case_number) where not soft-deleted.
        // Ensures no two active complaints in the same tenant share a case_number.
        DB::statement(
            'CREATE UNIQUE INDEX complaint_case_number_tenant_unique ON complaint (tenant_id, case_number) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS complaint_case_number_tenant_unique');
        Schema::dropIfExists('complaint');
    }
};
