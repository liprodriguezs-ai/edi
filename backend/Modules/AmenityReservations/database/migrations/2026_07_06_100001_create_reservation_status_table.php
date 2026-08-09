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
        Schema::create('reservation_status', function (Blueprint $table) {
            $table->comment('Central FSM catalog (global, not tenant-scoped) of amenity reservation statuses (pending_approval, pending_payment, confirmed, etc.).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable status label.');
            $table->string('color')->nullable()->comment('Hex color for UI badge.');
            $table->integer('order')->default(0)->comment('Display sort order.');
            $table->boolean('is_active')->default(true)->comment('Whether this status is selectable.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservation_status');
    }
};
