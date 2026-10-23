<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * CENTRAL catalog of appointment kinds. Central because the code branches on
     * it: a screening appointment opens the EDI capture when care starts, and the
     * default duration drives the booked time range.
     */
    public function up(): void
    {
        Schema::create('appointment_type', function (Blueprint $table) {
            $table->comment('Central catalog of appointment kinds (screening/stimulation/check_up) with their default duration');
            BaseModel::columns($table, false);
            $table->string('key')->comment("Machine-readable slug ('screening'/'stimulation'/'check_up').");
            $table->string('name')->comment('Human-readable name shown in the UI.');
            $table->smallInteger('default_duration_minutes')->comment('Booked length in minutes when the client does not send one.');
            $table->smallInteger('sort_order')->comment('Display order.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX appointment_type_key_unique ON appointment_type (key) '
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS appointment_type_key_unique');
        Schema::dropIfExists('appointment_type');
    }
};
