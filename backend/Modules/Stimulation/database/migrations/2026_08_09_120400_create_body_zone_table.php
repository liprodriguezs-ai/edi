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
     * TENANT-SCOPED admin-editable catalog: body zones used for the massage
     * (preparation section) and relaxation section of a stimulation session
     * (Cuello, Hombro/brazo, Mano, Tronco, Pierna, Pie, Cara, Pecho y abdomen,
     * Espalda), no branching logic.
     */
    public function up(): void
    {
        Schema::create('body_zone', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of body zones used in the massage/relaxation sections');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Body zone name shown in the UI (e.g. Cuello, Espalda).');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this body zone is currently offered.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX body_zone_tenant_name_unique ON body_zone (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS body_zone_tenant_name_unique');
        Schema::dropIfExists('body_zone');
    }
};
