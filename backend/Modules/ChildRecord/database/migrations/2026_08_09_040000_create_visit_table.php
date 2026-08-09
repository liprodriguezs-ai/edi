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
     * visit is the header (consulta) the future screening hangs from.
     * receipt_number is server-generated (VisitService, D9/D-15) and
     * immutable; physical_receipt_number is a separate nullable field for the
     * paper folio staff cross-reference (D-15).
     */
    public function up(): void
    {
        Schema::create('visit', function (Blueprint $table) {
            $table->comment('Visit header (consulta) at a health center; the future screening hangs from it');
            BaseModel::columns($table, true);
            $table->uuid('child_id')->index()->comment('FK to child');
            $table->uuid('health_center_id')->index()->comment('FK to health_center where the visit happened');
            $table->string('receipt_number', 20)->comment('Server-generated folio V-YYYY-NNNNNN; unique per tenant; immutable (D9/D-15)');
            $table->string('physical_receipt_number')->nullable()->comment('Optional paper-form folio written by staff, for cross-reference with the physical record (D-15)');
            $table->date('visit_date')->comment('Date of the visit (not in the future)');
            $table->uuid('practitioner_id')->index()->comment('FK to practitioner_profile who attended (D6)');
        });

        DB::statement(
            'CREATE UNIQUE INDEX visit_tenant_receipt_unique ON visit (tenant_id, receipt_number) '
            .'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE INDEX visit_tenant_child_date_idx ON visit (tenant_id, child_id, visit_date DESC)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS visit_tenant_receipt_unique');
        DB::statement('DROP INDEX IF EXISTS visit_tenant_child_date_idx');
        Schema::dropIfExists('visit');
    }
};
