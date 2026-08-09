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
        Schema::create('visitor_status', function (Blueprint $table): void {
            $table->comment('Catalog of visitor pass states (pending, used, expired, etc.)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Display name of the visitor status (e.g. Expected, Entered).');
            $table->string('color')->default('#6B7280')->comment('Hex color used to represent this status in the UI.');
            $table->integer('order')->default(0)->comment('Sort order for display in dropdowns.');
            $table->boolean('is_active')->default(true)->comment('Whether this status is available for assignment.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visitor_status');
    }
};
