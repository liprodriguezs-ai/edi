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
        Schema::create('module', function (Blueprint $table) {
            $table->string('name')->comment('Display name of the module shown in the sidebar menu');
            $table->string('icon')->nullable()->comment('Icon identifier from iconMap.ts used in the sidebar');
            $table->boolean('visible')->default(true)->comment('Whether the module appears in the sidebar menu');
            $table->string('group')->nullable()->comment('Sidebar group / section this module belongs to');
            $table->string('route')->nullable()->comment('Frontend route path for this module');
            $table->boolean('custom')->nullable()->default(false)->comment('Whether this is a tenant-custom module (true) vs a platform-shipped module (false)');
            BaseModel::columns($table);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module');
    }
};
