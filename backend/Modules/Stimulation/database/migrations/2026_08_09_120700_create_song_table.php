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
     * TENANT-SCOPED admin-editable catalog: songs suggested for the free-text
     * song columns of a stimulation session (D3 — free-text snapshot; this
     * catalog only feeds combobox suggestions). No default rows (admin fills,
     * design §8 Q2).
     */
    public function up(): void
    {
        Schema::create('song', function (Blueprint $table) {
            $table->comment('Tenant-scoped catalog of songs, feeds combobox suggestions only (D3)');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Song name shown in the UI/suggestions.');
            $table->string('description')->nullable()->comment('Optional free-text description.');
            $table->boolean('is_active')->default(true)->comment('Whether this song is currently offered.');
        });

        DB::statement(
            'CREATE UNIQUE INDEX song_tenant_name_unique ON song (tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS song_tenant_name_unique');
        Schema::dropIfExists('song');
    }
};
