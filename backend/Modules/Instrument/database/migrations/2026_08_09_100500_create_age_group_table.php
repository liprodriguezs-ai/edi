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
     * Versioned content (D4): age group of an instrument version. Official
     * groups (G1-G15) partition 30-2159 days; alternates (G1A-G14A) are
     * reached ONLY via alternate_age_group_id, never by age lookup -- their
     * legacy sentinel day/month ranges are discarded at seed time (D8).
     */
    public function up(): void
    {
        Schema::create('age_group', function (Blueprint $table) {
            $table->comment('Age group of an instrument version; official groups partition 30-2159 days, alternates are reached only via pointer');
            BaseModel::columns($table, false);
            $table->uuid('instrument_version_id')->index()->comment('Owning version (D4: present even though reachable via nothing else here -- age_group is the root of the content tree).');
            $table->string('code', 10)->comment("'G1'...'G15', 'G1A'...'G14A'; unique per version.");
            $table->string('name')->comment("Verbatim source label ('GRUPO 1'...); UI data.");
            $table->smallInteger('ordinal')->comment('1..29; unique per version; display/sort order.');
            $table->boolean('is_alternate')->comment('True for G1A-G14A.');
            $table->smallInteger('min_age_days')->nullable()->comment('Official groups: lower bound of the 15 exact day ranges. Alternates: NULL (sentinels discarded, D8).');
            $table->smallInteger('max_age_days')->nullable()->comment('Official groups: upper bound of the 15 exact day ranges. Alternates: NULL (sentinels discarded, D8).');
            $table->smallInteger('min_age_months')->nullable()->comment('Display-only month equivalent (1-71) for official groups; alternates NULL.');
            $table->smallInteger('max_age_months')->nullable()->comment('Display-only month equivalent (1-71) for official groups; alternates NULL.');
            $table->uuid('alternate_age_group_id')->nullable()->index()->comment('Official groups G2-G15 point to their alternate (is_alternate=true) row; G1 and every alternate row are NULL.');
            $table->text('materials_note')->nullable()->comment('Verbatim source "material" (test kit) text; official groups only.');
            $table->integer('legacy_id')->nullable()->comment('Original id_edi_clasificacion from the legacy dump, for traceability. Hidden from the API (REQ-INS-004).');
        });

        DB::statement(
            'CREATE UNIQUE INDEX age_group_version_code_unique ON age_group (instrument_version_id, code) '
            .'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX age_group_version_ordinal_unique ON age_group (instrument_version_id, ordinal) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS age_group_version_ordinal_unique');
        DB::statement('DROP INDEX IF EXISTS age_group_version_code_unique');
        Schema::dropIfExists('age_group');
    }
};
