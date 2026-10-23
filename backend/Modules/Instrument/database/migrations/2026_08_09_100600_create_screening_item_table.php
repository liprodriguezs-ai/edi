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
     * Versioned content (D4): a reactivo (item) of a group. `code` is
     * verbatim and opaque (D10) -- alternates reuse official codes with
     * different option colors, so uniqueness is per (age_group, code), NOT
     * global.
     */
    public function up(): void
    {
        Schema::create('screening_item', function (Blueprint $table) {
            $table->comment('Reactivo of a group; code is verbatim and opaque; two options decide a color');
            BaseModel::columns($table, false);
            $table->uuid('instrument_version_id')->index()->comment('Owning version (D4 redundancy, guards immutability/verify in O(1)).');
            $table->uuid('age_group_id')->index()->comment('Owning group.');
            $table->uuid('development_area_id')->index()->comment('Area (CO-R rows normalized to CO, D9).');
            $table->uuid('application_position_id')->index()->comment('Application position (legacy posicion 1-6).');
            $table->string('code', 20)->comment("Verbatim source code ('MG1.2', 'ALE15.4', 'CO-R 1'); opaque, never parsed (D10).");
            $table->text('question')->comment('Verbatim question text; may contain <br> markup from the source (Q6: kept verbatim, converted to line breaks only at render time).');
            $table->smallInteger('sort_order')->comment('Presentation order within (group, area).');
            $table->integer('legacy_id')->nullable()->comment('Original id_edi_evaluacion from the legacy dump, for traceability. Hidden from the API (REQ-INS-004).');
        });

        DB::statement(
            'CREATE UNIQUE INDEX screening_item_group_code_unique ON screening_item (age_group_id, code) '
            .'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX screening_item_group_area_sort_unique ON screening_item (age_group_id, development_area_id, sort_order) '
            .'WHERE deleted_at IS NULL'
        );

        Schema::table('screening_item', function (Blueprint $table) {
            $table->index(['instrument_version_id', 'age_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_item_group_area_sort_unique');
        DB::statement('DROP INDEX IF EXISTS screening_item_group_code_unique');
        Schema::dropIfExists('screening_item');
    }
};
