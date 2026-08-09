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
        Schema::create('board_period_status', function (Blueprint $table) {
            $table->comment('Catalog of board period statuses (current, closed).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable status label.');
            $table->string('color')->default('#6B7280')->comment('Hex color used to render this status badge in the UI.');
            $table->integer('order')->default(0)->comment('Display sort order for this status.');
            $table->boolean('is_active')->default(true)->comment('Whether this status is assignable to board periods.');
            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_period_status');
    }
};
