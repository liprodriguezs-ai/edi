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
        Schema::create('blacklist_type', function (Blueprint $table): void {
            $table->comment('Catalog of blacklist entry types (visitor name vs vehicle plate)');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Display name of the blacklist category (e.g. Person, Vehicle, Plate).');
            $table->integer('order')->default(0)->comment('Sort order for display in dropdowns.');
            $table->boolean('is_active')->default(true)->comment('Whether this blacklist type is currently in use.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blacklist_type');
    }
};
