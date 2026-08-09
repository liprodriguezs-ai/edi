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
        Schema::create('board_period', function (Blueprint $table) {
            $table->comment('Directorate period; opening it assigns Spatie roles, closing it revokes them.');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Period name or label (unique per tenant).');
            $table->date('start_date')->comment('Date the directorate period officially begins.');
            $table->date('end_date')->comment('Date the directorate period officially ends.');
            $table->foreignUuid('board_period_status_id')->constrained('board_period_status')->comment('FK to board_period_status.');
            $table->text('notes')->nullable()->comment('Optional administrative notes about the period.');
            $table->unique(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('board_period');
    }
};
