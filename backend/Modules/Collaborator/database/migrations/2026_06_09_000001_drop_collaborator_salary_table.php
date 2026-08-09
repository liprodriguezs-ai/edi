<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

/**
 * Retires the legacy CRM `collaborator_salary` table (board-staff-13 / sec-money-09).
 *
 * Its `amount` column was decimal(10,2) — violating the project money rule
 * (bigInteger minor units + MoneyIntegerCast) — and coexisted with the canonical
 * `collaborator.base_salary` (bigint minor units), creating a double source of
 * truth for the salary. The Kivo7 domain uses `base_salary` exclusively; the
 * table holds no productive Kivo7 data, so it is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('collaborator_salary');
    }

    /**
     * Restores the legacy schema as it existed before this migration.
     */
    public function down(): void
    {
        Schema::create('collaborator_salary', function (Blueprint $table) {
            $table->comment('Legacy salary record per collaborator (retired — restored only on rollback)');
            BaseModel::columns($table, true);
            $table->foreignUuid('collaborator_id')->nullable()->references('id')->on('collaborator')->comment('FK to collaborator');
            $table->decimal('amount', 10, 2)->comment('Salary amount (decimal — legacy; superseded by collaborator.base_salary in minor units)');
        });
    }
};
