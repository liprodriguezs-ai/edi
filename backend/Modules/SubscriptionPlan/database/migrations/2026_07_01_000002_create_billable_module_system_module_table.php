<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billable_module_system_module', function (Blueprint $table) {
            $table->comment('Pivot mapping which platform `module` rows each billable_module (feature) grants access to (billing v2)');

            // UUID PK with a DB-level default so attach()/sync() work (laravel-backend-architecture skill §2.9).
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            } else {
                $table->uuid('id')->primary()->default(DB::raw('(lower(hex(randomblob(16))))'));
            }

            $table->uuid('billable_module_id')->comment('FK to billable_module');
            $table->uuid('module_id')->comment('FK to module');
            $table->timestamps();

            $table->foreign('billable_module_id')->references('id')->on('billable_module')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('module')->cascadeOnDelete();
            $table->unique(['billable_module_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billable_module_system_module');
    }
};
