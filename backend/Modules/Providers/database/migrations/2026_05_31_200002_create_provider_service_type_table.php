<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_service_type', function (Blueprint $table) {
            BaseModel::columns($table, false);
            $table->string('name')->comment('Display name of the service type (e.g. Mantenimiento, Limpieza)');
            $table->integer('order')->default(0)->comment('Display order in dropdowns (ascending)');
            $table->boolean('is_active')->default(true)->comment('Whether this service type is available for selection');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_service_type');
    }
};
