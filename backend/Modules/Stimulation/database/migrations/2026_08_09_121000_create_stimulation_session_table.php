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
     * stimulation_session is a concrete domain entity (design D1): the four
     * official sections (integración, preparación, actividad central,
     * relajación) are column groups, not a generic form engine. Every column
     * is nullable except session_date, practitioner_id and the subject
     * (REQ-SS-001/002). Subject: exactly one of child_id/stimulation_group_id
     * is enforced at the API level (D5); the DB CHECK below is a backstop
     * for at-least-one.
     */
    public function up(): void
    {
        Schema::create('stimulation_session', function (Blueprint $table) {
            $table->comment('Early stimulation session (interacción oportuna): concrete record with the four official sections as column groups');
            BaseModel::columns($table, true);
            $table->uuid('child_id')->nullable()->index()->comment('FK to child (individual session); API enforces exactly one subject (REQ-SS-002)');
            $table->uuid('stimulation_group_id')->nullable()->index()->comment('FK to stimulation_group (group session)');
            $table->uuid('visit_id')->nullable()->index()->comment('FK to visit; individual sessions only; must belong to the same child');
            $table->date('session_date')->comment('Date the session took place (not in the future)');
            $table->uuid('practitioner_id')->index()->comment('FK to practitioner_profile who conducted the session (D6)');
            $table->uuid('integration_dynamic_id')->nullable()->index()->comment('Integration section: FK to tenant catalog stimulation_dynamic');
            $table->string('integration_material')->nullable()->comment('Integration section: material used; free-text snapshot, suggested from didactic_material (D3)');
            $table->string('integration_song')->nullable()->comment('Integration section: song used; free-text snapshot, suggested from song catalog');
            $table->string('preparation_song')->nullable()->comment('Preparation (massage) section: song used; free-text snapshot');
            $table->uuid('core_stimulation_area_id')->nullable()->index()->comment('Core section: FK to central stimulation_area worked in the central activity');
            $table->uuid('core_dynamic_id')->nullable()->index()->comment('Core section: FK to tenant catalog stimulation_dynamic');
            $table->text('core_activity')->nullable()->comment('Core section: description of the central activity performed');
            $table->string('core_material')->nullable()->comment('Core section: material used; free-text snapshot');
            $table->string('core_song')->nullable()->comment('Core section: song used; free-text snapshot');
            $table->text('relaxation_notes')->nullable()->comment('Relaxation section: notes');
            $table->text('observations')->nullable()->comment('General observations of the session');

            $table->index(['tenant_id', 'session_date']);
            $table->index(['tenant_id', 'child_id']);
            $table->index(['tenant_id', 'stimulation_group_id']);
        });

        DB::statement(
            'ALTER TABLE stimulation_session ADD CONSTRAINT stimulation_session_subject_check '
            .'CHECK (child_id IS NOT NULL OR stimulation_group_id IS NOT NULL)'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stimulation_session');
    }
};
