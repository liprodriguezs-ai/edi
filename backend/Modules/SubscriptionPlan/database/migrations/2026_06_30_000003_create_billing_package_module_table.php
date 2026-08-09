<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_package_module', function (Blueprint $table) {
            $table->comment('Pivot mapping which platform modules each billing package includes (for the "what is included" display in the catalog)');

            // UUID PK with a DB-level default so attach()/sync() work (laravel-backend-architecture skill §2.9).
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            } else {
                $table->uuid('id')->primary()->default(DB::raw('(lower(hex(randomblob(16))))'));
            }

            $table->uuid('billing_package_id')->comment('FK to billing_package');
            $table->uuid('module_id')->comment('FK to module');
            $table->timestamps();

            $table->foreign('billing_package_id')->references('id')->on('billing_package')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('module')->cascadeOnDelete();
            $table->unique(['billing_package_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_package_module');
    }
};
