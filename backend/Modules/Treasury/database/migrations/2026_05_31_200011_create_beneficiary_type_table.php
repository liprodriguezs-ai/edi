<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beneficiary_type', function (Blueprint $table) {
            $table->comment('Catalog of beneficiary types for expenses (employee, provider, other).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable beneficiary type label.');
            $table->integer('order')->default(0)->comment('Display sort order for this type.');
            $table->boolean('is_active')->default(true)->comment('Whether this type is selectable on new expenses.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beneficiary_type');
    }
};
