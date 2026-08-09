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
     * caregiver holds adult PII (REQ-XC-005). last_name_maternal is NULLABLE
     * per design.md D-13 (same rationale as child.last_name_maternal).
     */
    public function up(): void
    {
        Schema::create('caregiver', function (Blueprint $table) {
            $table->comment('Caregiver/tutor of one or more children; adult PII');
            BaseModel::columns($table, true);
            $table->string('first_name')->comment('Given name(s) of the caregiver');
            $table->string('last_name_paternal')->comment('Paternal surname');
            $table->string('last_name_maternal')->nullable()->comment('Maternal surname; nullable per D-13');
            $table->date('birth_date')->nullable()->comment('Caregiver birth date');
            $table->uuid('education_level_id')->index()->comment('FK to tenant catalog education_level');
            $table->uuid('occupation_id')->index()->comment('FK to tenant catalog occupation');
            $table->uuid('population_type_id')->index()->comment('FK to central population_type (official export dimension)');
            $table->string('phone', 30)->comment('Contact phone');
            $table->string('email')->nullable()->comment('Contact email');
            $table->boolean('has_social_program')->default(false)->comment('Whether the family receives a social program (EDI variable)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('caregiver');
    }
};
