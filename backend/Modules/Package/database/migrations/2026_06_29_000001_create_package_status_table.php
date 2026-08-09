<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('package_status', function (Blueprint $table) {
            $table->comment('Catálogo central FSM de estados del paquete — no editable por el tenant');
            BaseModel::columns($table, false);
            $table->string('name', 100)->comment('Nombre legible del estado');
            $table->string('key', 50)->unique()->comment('Clave interna del estado — usada por el código para ramificar FSM');
            $table->string('color', 20)->nullable()->comment('Color hex para badge de UI');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_status');
    }
};
