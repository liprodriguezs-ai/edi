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
     * Versioned content (D4): displayable outcome of a global rule --
     * headline + referral bullets, verbatim Spanish UI data from
     * resultadoGlobal.phtml. Created before global_scoring_rule because the
     * rule table references it.
     */
    public function up(): void
    {
        Schema::create('global_result_message', function (Blueprint $table) {
            $table->comment('Displayable outcome of a global rule: headline + referral bullets (Spanish UI data)');
            BaseModel::columns($table, false);
            $table->uuid('instrument_version_id')->index()->comment('Owning version.');
            $table->string('key')->comment("'red' | 'yellow_development' | 'yellow_social' | 'green'.");
            $table->uuid('result_color_id')->index()->comment('Color this message corresponds to (FK to result_color).');
            $table->string('headline')->comment('Verbatim result headline shown to the applicator.');
            $table->jsonb('referral_items')->comment('Array of verbatim Spanish referral bullets (legacy resultadoGlobal.phtml).');
            $table->smallInteger('sort_order')->comment('Display order.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX global_result_message_version_key_unique ON global_result_message (instrument_version_id, key) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS global_result_message_version_key_unique');
        Schema::dropIfExists('global_result_message');
    }
};
