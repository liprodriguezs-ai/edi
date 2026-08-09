<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_run', function (Blueprint $table): void {
            $table->string('phase')->nullable()->after('status')
                ->comment('Fase de la corrida: reading_file|processing|finalizing|done (canónico server-side; espejado en progress.phase para el FE)');
        });
    }

    public function down(): void
    {
        Schema::table('import_run', function (Blueprint $table): void {
            $table->dropColumn('phase');
        });
    }
};
