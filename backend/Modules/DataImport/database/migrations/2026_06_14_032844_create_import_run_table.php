<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_run', function (Blueprint $table): void {
            $table->comment('Registro de corrida de importación de pagos por clúster');
            BaseModel::columns($table, true); // tenant-scoped

            $table->string('status')->default('pending')->comment('Estado de la corrida: pending|processing|ready|failed');
            $table->string('file_path')->nullable()->comment('Ruta en storage del archivo subido para esta corrida');
            $table->json('progress')->nullable()->comment('Progreso de la corrida: {processed, total}');
            $table->json('report')->nullable()->comment('Reporte final: {total, units_created, residents_created, payments_registered, payments_skipped, bank_balance_delta, rows}');
            $table->text('error')->nullable()->comment('Mensaje de error capturado cuando status=failed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_run');
    }
};
