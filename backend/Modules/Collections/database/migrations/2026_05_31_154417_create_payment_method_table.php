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
        Schema::create('payment_method', function (Blueprint $table) {
            $table->comment('Global catalog of accepted payment methods.');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable method label (e.g. cash, transfer, deposit).');
            $table->integer('order')->default(0)->comment('Display sort order.');
            $table->boolean('is_active')->default(true)->comment('Whether this method is selectable.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_method');
    }
};
