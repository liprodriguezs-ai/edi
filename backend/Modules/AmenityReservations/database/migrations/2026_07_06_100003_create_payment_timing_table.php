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
        Schema::create('payment_timing', function (Blueprint $table) {
            $table->comment('Central behavior-selector catalog (global, not tenant-scoped): when the cost/deposit of an amenity must be paid — pay_later (after approval, before the reservation is confirmed) or pay_upfront (at booking time).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable timing label.');
            $table->integer('order')->default(0)->comment('Display sort order.');
            $table->boolean('is_active')->default(true)->comment('Whether this timing is selectable.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_timing');
    }
};
