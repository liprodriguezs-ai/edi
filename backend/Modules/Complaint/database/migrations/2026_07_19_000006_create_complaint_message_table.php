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
        Schema::create('complaint_message', function (Blueprint $table) {
            $table->comment('Hilo de mensajes de seguimiento de una denuncia');
            BaseModel::columns($table, true);

            $table->foreignUuid('complaint_id')->references('id')->on('complaint')->cascadeOnDelete()->comment('FK denuncia padre del hilo');
            $table->foreignUuid('user_id')->nullable()->references('id')->on('user')->nullOnDelete()->comment('FK usuario autor del mensaje');
            $table->text('body')->comment('Contenido del mensaje de seguimiento');

            $table->index('complaint_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complaint_message');
    }
};
