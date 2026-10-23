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
     * screening_answer stores one item answer with the color resolved
     * server-side at write time (D4) -- the client payload only ever carries
     * ids, never a color.
     */
    public function up(): void
    {
        Schema::create('screening_answer', function (Blueprint $table) {
            $table->comment('Answer of one item in one screening; color resolved server-side');
            BaseModel::columns($table, true);
            $table->uuid('screening_id')->index()->comment('FK to screening');
            $table->uuid('screening_item_id')->index()->comment('FK to screening_item (own or alternate group)');
            $table->uuid('screening_item_option_id')->comment('FK to the selected screening_item_option');
            $table->uuid('result_color_id')->comment('Snapshot of the selected option color, resolved server-side (D4)');
            $table->boolean('is_auto_derived')->default(false)->comment('True when scoring overrode this answer with an engine-derived value (REQ-SCR-007)');
        });

        DB::statement(
            'CREATE UNIQUE INDEX screening_answer_item_unique ON screening_answer (screening_id, screening_item_id) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_answer_item_unique');
        Schema::dropIfExists('screening_answer');
    }
};
