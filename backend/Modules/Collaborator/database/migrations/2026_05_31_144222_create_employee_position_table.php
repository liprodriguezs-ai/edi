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
        Schema::create('employee_position', function (Blueprint $table) {
            $table->comment('Catalog of employee job positions (global, not tenant-scoped)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Position label (e.g. Security, Gardener)');
            $table->integer('order')->default(0)->comment('Display sort order');
            $table->boolean('is_active')->default(true)->comment('Whether this position is available for selection');
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_position');
    }
};
