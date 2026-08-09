<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_system', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('module_id')->comment('FK to module');
            $table->uuid('system_id')->comment('FK to system');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('Display order of this module within its system (ascending)');
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('module')->cascadeOnDelete();
            $table->foreign('system_id')->references('id')->on('system')->cascadeOnDelete();
            $table->unique(['module_id', 'system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_system');
    }
};
