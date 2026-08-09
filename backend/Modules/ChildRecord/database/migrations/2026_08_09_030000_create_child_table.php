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
     * child holds sensitive PII of a minor (REQ-XC-005). last_name_maternal is
     * NULLABLE per design.md D-13: requiring it forces staff to invent data for
     * foreign nationals, children of unknown parentage, and single-surname
     * registrations.
     */
    public function up(): void
    {
        Schema::create('child', function (Blueprint $table) {
            $table->comment('Child (menor evaluado) registered in the tenant organization; sensitive PII of a minor');
            BaseModel::columns($table, true);
            $table->string('first_name')->comment('Given name(s) of the child');
            $table->string('last_name_paternal')->comment('Paternal surname');
            $table->string('last_name_maternal')->nullable()->comment('Maternal surname; nullable per D-13 (foreign nationals, single-surname registrations)');
            $table->date('birth_date')->comment('Birth date; drives age-in-months/days for screening');
            $table->uuid('sex_id')->index()->comment('FK to central sex catalog');
            $table->string('curp', 18)->nullable()->comment('CURP, stored uppercase; format-validated. Cross-check against birth_date/sex is a WARNING only (D-16), see curp_mismatch');
            $table->boolean('curp_mismatch')->default(false)->comment('True when the CURP embedded birth-date/sex segment disagrees with birth_date/sex_id (D-16: non-blocking, flagged for review)');
            $table->string('external_id')->nullable()->comment("Record id (expediente) in the health center's own system");
            $table->uuid('health_center_id')->index()->comment('FK to health_center where the child is attended');
            $table->boolean('is_schooled')->default(false)->comment('Whether the child attends daycare/school (EDI variable)');
            $table->text('notes')->nullable()->comment('Internal clinical/administrative notes');
            $table->index(['tenant_id', 'birth_date']);
            $table->index(['tenant_id', 'last_name_paternal']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX child_tenant_curp_unique ON child (tenant_id, curp) '
            .'WHERE curp IS NOT NULL AND deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX child_tenant_external_unique ON child (tenant_id, external_id) '
            .'WHERE external_id IS NOT NULL AND deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS child_tenant_curp_unique');
        DB::statement('DROP INDEX IF EXISTS child_tenant_external_unique');
        Schema::dropIfExists('child');
    }
};
