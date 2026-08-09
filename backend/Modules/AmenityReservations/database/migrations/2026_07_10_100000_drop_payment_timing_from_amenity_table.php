<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

/**
 * Removes the payment_timing behavior selector: the module already shipped
 * (merged into main), so the original create migrations
 * (2026_07_06_100003_create_payment_timing_table,
 * 2026_07_06_100004_create_amenity_table) stay untouched — append-only
 * history. This is a follow-up ALTER/DROP pair instead.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('amenity', function (Blueprint $table) {
            $table->dropForeign(['payment_timing_id']);
            $table->dropColumn('payment_timing_id');
        });

        Schema::dropIfExists('payment_timing');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('payment_timing', function (Blueprint $table) {
            $table->comment('Central behavior-selector catalog (global, not tenant-scoped): when the cost/deposit of an amenity must be paid — pay_later (after approval, before the reservation is confirmed) or pay_upfront (at booking time).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable timing label.');
            $table->integer('order')->default(0)->comment('Display sort order.');
            $table->boolean('is_active')->default(true)->comment('Whether this timing is selectable.');
        });

        Schema::table('amenity', function (Blueprint $table) {
            $table->uuid('payment_timing_id')->nullable()->comment('FK to payment_timing (pay_later or pay_upfront).');
            $table->foreign('payment_timing_id')->references('id')->on('payment_timing');
        });
    }
};
