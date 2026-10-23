<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The group the child's age would have applied, corrected age included.
     * In "Prueba EDI a elección" the applied group is the chosen one and
     * age_group_id is the CHRONOLOGICAL group, so neither tells which group the
     * age rules (with the premature correction) would have used. Nullable:
     * screenings created before this column keep NULL and the UI falls back to
     * age_group_id. Existing rows are left untouched.
     */
    public function up(): void
    {
        Schema::table('screening', function (Blueprint $table) {
            $table->uuid('age_derived_age_group_id')
                ->nullable()
                ->index()
                ->after('age_group_selection_mode')
                ->comment('Group the age rules would apply (corrected age included); equals applied_age_group_id in age mode, differs in chosen mode. NULL for screenings created before it existed');
        });
    }

    public function down(): void
    {
        Schema::table('screening', function (Blueprint $table) {
            $table->dropIndex(['age_derived_age_group_id']);
            $table->dropColumn('age_derived_age_group_id');
        });
    }
};
