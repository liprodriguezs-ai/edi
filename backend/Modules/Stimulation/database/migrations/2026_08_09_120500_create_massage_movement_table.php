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
     * TENANT-SCOPED admin-editable catalog: massage movements applied to a
     * body zone in the preparation section of a stimulation session (Flexión,
     * Extensión, Circunducción, Media circunducción, Arriba-abajo,
     * Abducción-aducción, Amasamiento de dedos), no branching logic.
     */
    public function up(): void
    {
        Schema::create('massage_movement', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of massage movements used in the preparation section');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Movement name shown in the UI (e.g. Flexión, Extensión).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this movement is currently offered.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX massage_movement_tenant_name_unique ON massage_movement (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS massage_movement_tenant_name_unique');
        Schema::dropIfExists('massage_movement');
    }
};
