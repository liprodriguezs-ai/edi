<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * charge_concept is the IDENTITY of a billable item (code + name + category).
     * Its CHARGE BEHAVIOR (amount mode/amounts, grace, periodicity, validity window)
     * lives in charge_policy (1:N), so a single concept can carry several policies.
     */
    public function up(): void
    {
        Schema::create('charge_concept', function (Blueprint $table) {
            $table->comment('Identity of a billable item (code/name/category). Charge behavior lives in charge_policy.');
            BaseModel::columns($table, true);
            $table->string('code')->comment('Uppercase identifier, e.g. MANT_MENSUAL');
            $table->string('name')->comment('Display name of the charge concept.');
            $table->text('description')->nullable()->comment('Optional details about what this concept covers.');
            $table->uuid('charge_concept_category_id')->comment('FK to charge_concept_category');
            $table->boolean('is_active')->default(true)->comment('Master switch: when false, none of its policies generate charges.');

            $table->foreign('charge_concept_category_id')->references('id')->on('charge_concept_category');
            $table->index('charge_concept_category_id');

            // Unique code per tenant (soft-delete aware)
        });

        DB::statement(
            'CREATE UNIQUE INDEX charge_concept_tenant_code_unique ON "charge_concept" '
            .'(tenant_id, code) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS charge_concept_tenant_code_unique');
        Schema::dropIfExists('charge_concept');
    }
};
