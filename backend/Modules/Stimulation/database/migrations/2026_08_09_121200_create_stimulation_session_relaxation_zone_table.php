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
     * Body zone relaxed in the relaxation section of a session (REQ-SS-003).
     * Written ONLY through the session's declarative sync (design D7) — no
     * standalone endpoint. Uniqueness among non-deleted rows per
     * (session_id, body_zone_id).
     */
    public function up(): void
    {
        Schema::create('stimulation_session_relaxation_zone', function (Blueprint $table) {
            $table->comment('Body zone relaxed in the relaxation section of a session');
            BaseModel::columns($table, true);
            $table->uuid('session_id')->index()->comment('FK to stimulation_session');
            $table->uuid('body_zone_id')->index()->comment('FK to tenant catalog body_zone');
        });

        DB::statement(
            'CREATE UNIQUE INDEX stimulation_session_relaxation_zone_unique ON stimulation_session_relaxation_zone '
            .'(session_id, body_zone_id) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stimulation_session_relaxation_zone_unique');
        Schema::dropIfExists('stimulation_session_relaxation_zone');
    }
};
