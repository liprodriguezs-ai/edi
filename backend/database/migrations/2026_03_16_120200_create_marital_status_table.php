<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marital_status', function (Blueprint $table) {
            BaseModel::columns($table);
            $table->string('name')->comment('Marital status label (e.g., "Soltero", "Casado")');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marital_status');
    }
};
