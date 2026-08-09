<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_type', function (Blueprint $table) {
            $table->comment('Catalog of residential unit types (global, not tenant-scoped)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Unit type label (e.g. House, Apartment, Penthouse)');
            $table->integer('order')->default(0)->comment('Display sort order');
            $table->boolean('is_active')->default(true)->comment('Whether this type is available for selection');
            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_type');
    }
};
