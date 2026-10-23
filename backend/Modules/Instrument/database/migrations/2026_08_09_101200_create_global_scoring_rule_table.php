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
     * Versioned content (D4): ordered global rule of an official group.
     * `condition` is jsonb in the closed grammar of design §5.4 (6 operators,
     * validated by InstrumentVerificationService). Evaluation is
     * first-match-wins by ascending sequence; the last rule of every group
     * is the mandatory catch-all {"all":[]}. Alternate groups have NO rows.
     */
    public function up(): void
    {
        Schema::create('global_scoring_rule', function (Blueprint $table) {
            $table->comment('Ordered global rule of an official group; jsonb condition in the closed grammar; first match wins');
            BaseModel::columns($table, false);
            $table->uuid('instrument_version_id')->index()->comment('Owning version.');
            $table->uuid('age_group_id')->index()->comment('Official group this rule belongs to.');
            $table->smallInteger('sequence')->comment('Evaluation order within the group; unique per group; the highest sequence is always the catch-all.');
            $table->jsonb('condition')->comment('Closed-grammar condition (design §5.4: all/any/not/count/sum/color_in). Exactly one operator key per object.');
            $table->uuid('result_color_id')->index()->comment('Color this rule resolves to (FK to result_color).');
            $table->uuid('global_result_message_id')->index()->comment('Message shown for this outcome (FK to global_result_message, same version, matching color).');
            $table->string('source')->comment("Provenance tag: 'legacy_js' | 'oracle_confirmed' (design.md §13, G15) | 'manual'.");
            $table->string('notes')->nullable()->comment('Free-text provenance note.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX global_scoring_rule_group_sequence_unique ON global_scoring_rule (age_group_id, sequence) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS global_scoring_rule_group_sequence_unique');
        Schema::dropIfExists('global_scoring_rule');
    }
};
