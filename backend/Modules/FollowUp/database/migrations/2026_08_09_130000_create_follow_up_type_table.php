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
     * CENTRAL FSM-adjacent catalog (design D6/REQ-FUP-001): the color-matrix
     * creation logic (FollowUpCreationService) and the auto-close logic
     * (CreateFollowUpsFromScreening) branch on these fixed UUIDs, so it is
     * central and read-only for tenant roles, seeded once by CatalogSeeder.
     */
    public function up(): void
    {
        Schema::create('follow_up_type', function (Blueprint $table) {
            $table->comment('Central catalog of follow-up conducts (reassess_3_months/specialist_referral/confirmatory_test)');
            BaseModel::columns($table, false);
            $table->string('key')->comment("Machine-readable slug ('reassess_3_months'/'specialist_referral'/'confirmatory_test').");
            $table->string('name')->comment('Human-readable name shown in the UI.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX follow_up_type_key_unique ON follow_up_type (key) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS follow_up_type_key_unique');
        Schema::dropIfExists('follow_up_type');
    }
};
