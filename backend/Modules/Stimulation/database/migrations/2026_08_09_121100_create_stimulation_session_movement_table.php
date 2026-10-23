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
     * Massage pair applied in the preparation section (REQ-SS-003): body_zone
     * x massage_movement. Written ONLY through the session's declarative sync
     * (design D7) — no standalone endpoint. Uniqueness among non-deleted rows
     * per (session_id, body_zone_id, massage_movement_id).
     */
    public function up(): void
    {
        Schema::create('stimulation_session_movement', function (Blueprint $table) {
            $table->comment('Massage pair applied in the preparation section of a session: body zone x movement');
            BaseModel::columns($table, true);
            $table->uuid('session_id')->index()->comment('FK to stimulation_session');
            $table->uuid('body_zone_id')->index()->comment('FK to tenant catalog body_zone');
            $table->uuid('massage_movement_id')->index()->comment('FK to tenant catalog massage_movement');
        });

        DB::statement(
            'CREATE UNIQUE INDEX stimulation_session_movement_unique ON stimulation_session_movement '
            .'(session_id, body_zone_id, massage_movement_id) WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stimulation_session_movement_unique');
        Schema::dropIfExists('stimulation_session_movement');
    }
};
