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
        Schema::create('package', function (Blueprint $table) {
            $table->comment('Paquetes recibidos en la portería del clúster');
            BaseModel::columns($table, true);

            $table->foreignUuid('unit_id')->references('id')->on('unit')->restrictOnDelete()->comment('FK unidad destino del paquete');
            $table->foreignUuid('package_status_id')->references('id')->on('package_status')->restrictOnDelete()->comment('FK estado FSM del paquete; gestionado solo por PackageService');
            $table->foreignUuid('recipient_user_id')->nullable()->references('id')->on('user')->nullOnDelete()->comment('FK residente nominal destinatario (informativo)');
            $table->string('recipient_name', 150)->nullable()->comment('Nombre libre del destinatario si el usuario no está registrado');
            $table->foreignUuid('package_carrier_id')->nullable()->references('id')->on('package_carrier')->restrictOnDelete()->comment('FK transportista del catálogo tenant');
            $table->string('carrier_other', 100)->nullable()->comment('Transportista libre cuando no está en catálogo');
            $table->foreignUuid('package_type_id')->nullable()->references('id')->on('package_type')->restrictOnDelete()->comment('FK tipo de paquete del catálogo tenant');
            $table->string('tracking_number', 100)->nullable()->comment('Número de rastreo del envío');
            $table->text('description')->nullable()->comment('Descripción breve del contenido');
            $table->string('storage_location', 100)->nullable()->comment('Ubicación física de almacenamiento ej. Estante A3');
            $table->string('pickup_code', 9)->nullable()->comment('Código de retiro XXXX-XXXX — oculto por defecto; único por tenant');
            $table->dateTime('received_at')->comment('Timestamp de recepción en la portería');
            $table->uuid('received_by_user_id')->nullable()->comment('ID del guardia que registró — sin FK (patrón access_log.guard_user_id)');
            $table->dateTime('notified_at')->nullable()->comment('Timestamp de envío de notificación de llegada a residentes');
            $table->dateTime('reminded_at')->nullable()->comment('Timestamp de envío de recordatorio de retención; null = no enviado');
            $table->dateTime('picked_up_at')->nullable()->comment('Timestamp de entrega al residente o proxy');
            $table->uuid('picked_up_by_user_id')->nullable()->comment('ID del guardia que entregó — sin FK');
            $table->string('pickup_proxy_name', 150)->nullable()->comment('Nombre del proxy autorizado para retirar');
            $table->string('pickup_proxy_relationship', 100)->nullable()->comment('Relación del proxy con el residente');
            $table->text('incident_reason')->nullable()->comment('Razón del incidente, devolución o pérdida');

            $table->index('unit_id');
            $table->index('package_carrier_id');
            // Composite: the pending/aging reports filter by status and order by received_at.
            $table->index(['package_status_id', 'received_at'], 'pkg_status_received_idx');
        });

        // Partial unique index on (tenant_id, pickup_code) where not soft-deleted.
        // Ensures no two active packages in the same tenant share a pickup code.
        DB::statement(
            'CREATE UNIQUE INDEX pkg_pickup_code_tenant_unique ON package (tenant_id, pickup_code) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package');
    }
};
