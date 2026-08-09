<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_user', function (Blueprint $table) {
            $table->comment('Pivot linking a resident (user) to a unit with relationship type and dates');
            BaseModel::columns($table, true);
            $table->foreignUuid('unit_id')->constrained('unit')->cascadeOnDelete()->comment('FK to unit');
            // user_id references the central user table (single-DB; no cross-boundary FK)
            $table->uuid('user_id')->index()->comment('FK to user — resident linked to this unit');
            $table->foreignUuid('unit_relationship_id')->constrained('unit_relationship')->cascadeOnDelete()->comment('FK to unit_relationship — type of residency');
            $table->boolean('is_billing_owner')->default(false)->comment('Whether this resident is responsible for billing');
            $table->boolean('is_legal_representative')->default(false)->comment('Whether this resident is the legal representative of the unit');
            $table->date('start_date')->nullable()->comment('Date the residency link began');
            $table->date('end_date')->nullable()->comment('Date the residency link ended (null = currently active)');
            $table->text('notes')->nullable()->comment('Free-form notes about this resident-unit link');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_user');
    }
};
