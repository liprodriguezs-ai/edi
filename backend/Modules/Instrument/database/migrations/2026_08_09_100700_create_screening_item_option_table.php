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
     * Versioned content (D4): one of the two answers of an item and the
     * color it produces. sort_order preserves the legacy valor1/valor2
     * presentation order (D11) -- 4 items have green first, never assumed.
     */
    public function up(): void
    {
        Schema::create('screening_item_option', function (Blueprint $table) {
            $table->comment('One of the two answers of an item and the color it produces');
            BaseModel::columns($table, false);
            $table->uuid('instrument_version_id')->index()->comment('Owning version (D4 redundancy, guards immutability/verify in O(1)).');
            $table->uuid('screening_item_id')->index()->comment('Owning item.');
            $table->string('label', 10)->comment("Verbatim source label ('SI' / 'NO').");
            $table->smallInteger('sort_order')->comment('1|2 = legacy valor1/valor2 presentation order (D11); NOT an assumption about which is "bad".');
            $table->uuid('result_color_id')->index()->comment('Color this answer produces (FK to result_color).');
        });

        DB::statement(
            'CREATE UNIQUE INDEX screening_item_option_item_sort_unique ON screening_item_option (screening_item_id, sort_order) '
            .'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX screening_item_option_item_label_unique ON screening_item_option (screening_item_id, label) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS screening_item_option_item_label_unique');
        DB::statement('DROP INDEX IF EXISTS screening_item_option_item_sort_unique');
        Schema::dropIfExists('screening_item_option');
    }
};
