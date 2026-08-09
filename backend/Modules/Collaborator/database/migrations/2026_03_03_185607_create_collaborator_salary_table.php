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
        Schema::create('collaborator_salary', function (Blueprint $table) {
            $table->comment('Legacy salary record per collaborator (retired — see drop migration)');
            BaseModel::columns($table, true);
            $table->foreignUuid('collaborator_id')->nullable()->references('id')->on('collaborator')->comment('FK to collaborator');
            $table->decimal('amount', 10, 2)->comment('Salary amount (decimal — legacy; superseded by collaborator.base_salary in minor units)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collaborator_salary');
    }
};
