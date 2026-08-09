<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            // Kivo7 employee domain fields — added via new migration (append-only)
            $table->foreignUuid('employee_position_id')->nullable()->after('work_shift_id')->comment('FK to employee_position catalog');
            $table->foreignUuid('pay_frequency_id')->nullable()->after('employee_position_id')->comment('FK to pay_frequency catalog');
            $table->foreignUuid('employee_shift_id')->nullable()->after('pay_frequency_id')->comment('FK to employee_shift catalog');
            // base_salary stored in minor currency units (e.g. 150000 = $1,500.00 MXN)
            $table->bigInteger('base_salary')->nullable()->after('employee_shift_id')->comment('Minor units (cents)');
            $table->json('work_days')->nullable()->after('base_salary')->comment('Array of scheduled work day names (e.g. ["Monday","Tuesday"])');
            $table->date('hire_date')->nullable()->after('work_days')->comment('Date the employee was hired');
            $table->date('termination_date')->nullable()->after('hire_date')->comment('Date the employee was terminated');
            $table->text('termination_reason')->nullable()->after('termination_date')->comment('Reason for termination');
            $table->boolean('is_active')->default(true)->after('termination_reason')->comment('Whether the employee is currently active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->dropColumn([
                'employee_position_id',
                'pay_frequency_id',
                'employee_shift_id',
                'base_salary',
                'work_days',
                'hire_date',
                'termination_date',
                'termination_reason',
                'is_active',
            ]);
        });
    }
};
