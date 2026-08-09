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
        Schema::create('employee_shift', function (Blueprint $table) {
            $table->comment('Catalog of work shifts for employees (global, not tenant-scoped)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Shift label (e.g. Morning, Afternoon, Night)');
            $table->integer('order')->default(0)->comment('Display sort order');
            $table->boolean('is_active')->default(true)->comment('Whether this shift is available for selection');
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_shift');
    }
};
