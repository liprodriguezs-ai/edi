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
     * charge_surcharge_rule defines the late-fee (recargo moratorio) schedule of a
     * charge_policy. A policy owns 0..N rules (1:N) so the surcharge can ESCALATE by
     * months overdue: each rule applies from `from_month` (1-based month of delinquency).
     * The accrual engine (ChargeStatusService::accrueSurcharge) picks the active rule
     * with the greatest from_month <= monthsOverdue.
     *
     * surcharge_value semantics depend on the surcharge_mode:
     *   - percent → BASIS POINTS (500 = 5.00%) applied to the overdue base.
     *   - fixed   → flat minor units (cents).
     * recurrence:
     *   - one_time          → the value is applied once.
     *   - per_month_overdue → the value scales by the number of months overdue.
     * cap_amount caps the TOTAL accrued surcharge (minor units, nullable = no cap).
     */
    public function up(): void
    {
        Schema::create('charge_surcharge_rule', function (Blueprint $table) {
            $table->comment('Late-fee (recargo moratorio) schedule of a charge_policy: escalating rules by months overdue.');
            BaseModel::columns($table, true);
            $table->uuid('charge_policy_id')->comment('FK to charge_policy');
            $table->smallInteger('from_month')->comment('1-based month of delinquency from which this rule applies (escalation step).');
            $table->uuid('surcharge_mode_id')->comment('FK to surcharge_mode (central): percent (basis points) or fixed (minor units).');
            $table->bigInteger('surcharge_value')->comment('percent = basis points (500 = 5.00%); fixed = minor units (cents).');
            $table->string('recurrence')->default('per_month_overdue')->comment("'one_time' (applied once) or 'per_month_overdue' (scales by months overdue).");
            $table->bigInteger('cap_amount')->nullable()->comment('Maximum total accrued surcharge in minor units (cents); null = no cap.');
            $table->boolean('is_active')->default(true)->comment('When false, this rule is ignored by the accrual engine.');

            $table->foreign('charge_policy_id')->references('id')->on('charge_policy');
            $table->foreign('surcharge_mode_id')->references('id')->on('surcharge_mode');

            $table->index(['charge_policy_id', 'from_month']);
            $table->index('surcharge_mode_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_surcharge_rule');
    }
};
