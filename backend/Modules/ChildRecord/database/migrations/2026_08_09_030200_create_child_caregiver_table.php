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
     * child_caregiver is the pivot carrying kinship + the primary-contact
     * invariant (REQ-CH-005 / REQ-CH-006): at most one active primary per
     * child, enforced by a DB partial unique index (never only app-level).
     */
    public function up(): void
    {
        Schema::create('child_caregiver', function (Blueprint $table) {
            $table->comment('Pivot child<->caregiver with kinship and primary-contact flag');
            BaseModel::columns($table, true);
            $table->uuid('child_id')->index()->comment('FK to child');
            $table->uuid('caregiver_id')->index()->comment('FK to caregiver (same tenant, validated)');
            $table->uuid('caregiver_relationship_id')->index()->comment('FK to tenant catalog caregiver_relationship (kinship)');
            $table->boolean('is_primary')->default(false)->comment('Primary caregiver/contact for the child (at most one active per child)');
        });

        DB::statement(
            'CREATE UNIQUE INDEX child_caregiver_pair_unique ON child_caregiver (child_id, caregiver_id) '
            .'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX child_caregiver_primary_unique ON child_caregiver (child_id) '
            .'WHERE is_primary AND deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS child_caregiver_pair_unique');
        DB::statement('DROP INDEX IF EXISTS child_caregiver_primary_unique');
        Schema::dropIfExists('child_caregiver');
    }
};
