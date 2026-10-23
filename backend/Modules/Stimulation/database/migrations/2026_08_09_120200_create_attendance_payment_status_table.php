<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CENTRAL catalog (D4): fixed UUIDs, seeded once by CatalogSeeder. Payment
     * status of a group member's attendance/enrollment (beca/pagado/no pagado).
     */
    public function up(): void
    {
        Schema::create('attendance_payment_status', function (Blueprint $table) {
            $table->comment('Central catalog of attendance payment statuses (fixed UUIDs; defaults a group member row and prefills each attendance entry)');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment("Machine-readable slug ('scholarship'/'paid'/'unpaid').");
            $table->string('name')->comment('Human-readable name shown in the UI (Beca/Pagado/No pagado).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_payment_status');
    }
};
