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
     * surcharge_mode is a CENTRAL fixed-UUID catalog (no tenant_id) defining HOW a
     * late-fee surcharge value is interpreted:
     *   - 'percent': surcharge_value is BASIS POINTS (500 = 5.00%) of the overdue base.
     *   - 'fixed': surcharge_value is a flat amount in minor units (cents).
     * The code branches on the row (percent vs fixed) → central, mirrors amount_mode.
     */
    public function up(): void
    {
        Schema::create('surcharge_mode', function (Blueprint $table) {
            $table->comment('Global catalog defining how a late-fee surcharge value is interpreted (percent basis-points vs fixed minor units).');
            BaseModel::columns($table, false);
            $table->string('name')->comment('Human-readable mode label (e.g. percent, fixed).');
            $table->integer('order')->default(0)->comment('Display sort order.');
            $table->boolean('is_active')->default(true)->comment('Whether this mode is selectable.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('surcharge_mode');
    }
};
