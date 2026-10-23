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
     * CENTRAL registry (D1): an immutable edition of an instrument. All
     * versioned content hangs from this row via instrument_version_id.
     * published_at NULL = draft (mutable, seeder-only); once set, content is
     * frozen (D5) and only is_default may still change on this row itself.
     */
    public function up(): void
    {
        Schema::create('instrument_version', function (Blueprint $table) {
            $table->comment('Immutable edition of an instrument; all content hangs from it; published versions never change');
            BaseModel::columns($table, false);
            $table->uuid('instrument_id')->index()->comment('FK to instrument (no DB FK, app-level integrity).');
            $table->string('key')->comment("Version slug ('edi-legacy-2013' / 'edi-2022-2ed'); unique per instrument (partial).");
            $table->string('name')->comment('Human-readable edition name shown in the UI.');
            $table->string('age_calculation_mode')->comment("'thirty_day_month' (legacy: month=30 days, year=360) | 'calendar' (real calendar). Consumed by the future scoring engine.");
            $table->boolean('is_default')->default(false)->comment('Version used for NEW screenings; requires published_at set.');
            $table->timestamp('published_at')->nullable()->comment('NULL = draft (mutable); set once at publish time, never cleared.');
            $table->char('content_hash', 64)->nullable()->comment('SHA-256 of the canonical content serialization; set at publish, recomputed by instrument:verify.');
            $table->text('source_note')->nullable()->comment('Provenance notes: dump date, transcription/extrapolation notes.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX instrument_version_instrument_key_unique ON instrument_version (instrument_id, key) '
            .'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX instrument_version_one_default_unique ON instrument_version (instrument_id) '
            .'WHERE is_default AND deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS instrument_version_one_default_unique');
        DB::statement('DROP INDEX IF EXISTS instrument_version_instrument_key_unique');
        Schema::dropIfExists('instrument_version');
    }
};
