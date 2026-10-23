<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CENTRAL support catalog (D2/D3), NOT versioned: the physical position
     * the child is evaluated in (from cat_edi_posicion), shared vocabulary
     * across every instrument edition. legacy_id traces back to the dump.
     */
    public function up(): void
    {
        Schema::create('application_position', function (Blueprint $table) {
            $table->comment('Central application-position catalog (supine/prone/sitting/...); shared vocabulary across every instrument edition, not versioned');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment('Machine-readable slug (supine/prone/sitting/on_floor/standing/not_applicable).');
            $table->string('name')->comment('Human-readable name shown in the UI (Supina/Prona/Sentado/En piso/De pie/No aplica).');
            $table->smallInteger('legacy_id')->comment('Original id_edi_posicion from the legacy dump (1-6), for traceability.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_position');
    }
};
