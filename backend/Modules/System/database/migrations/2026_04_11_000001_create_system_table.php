<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system', function (Blueprint $table) {
            BaseModel::columns($table);
            $table->string('name')->comment('Display name of the system (e.g. CRM, RRHH)');
            $table->string('slug')->unique()->comment('Machine-readable identifier used in routes and seeding');
            $table->text('description')->nullable()->comment('Optional description of what this system covers');
            $table->string('icon')->nullable()->comment('Icon identifier from iconMap.ts shown in the top navigation');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Display order of systems in the navigation (ascending)');
            $table->boolean('is_active')->default(true)->comment('Whether this system is currently enabled on the platform');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system');
    }
};
