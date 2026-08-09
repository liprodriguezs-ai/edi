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
        Schema::create('collaborator', function (Blueprint $table) {
            $table->comment('Cluster employee (security, gardener, cleaner, maintenance, admin, etc.)');
            BaseModel::columns($table, true);
            $table->uuid('user_id')->nullable()->comment('FK to user — linked system user account');
            $table->uuid('job_position_id')->nullable()->comment('FK to job_position catalog');
            $table->uuid('area_id')->nullable()->comment('FK to area catalog');
            $table->uuid('contract_type_id')->nullable()->comment('FK to contract_type catalog');
            $table->string('rfc')->nullable()->comment('Mexican tax ID (RFC)');
            $table->date('date_of_birth')->nullable()->comment('Date of birth');
            $table->date('contract_at')->nullable()->comment('Contract start date');
            $table->date('contract_end_at')->nullable()->comment('Contract end date');
            $table->string('gender')->nullable()->comment('Gender identifier');
            $table->string('curp')->nullable()->comment('Mexican CURP identifier');
            $table->string('number')->nullable()->comment('Employee identification number');
            $table->boolean('contracted')->default(false)->comment('Whether the employee is under a formal contract');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('collaborator');
    }
};
