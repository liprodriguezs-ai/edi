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
     */
    public function up(): void
    {

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp";');
        }
        Schema::create('company', function (Blueprint $table) {
            BaseModel::columns($table, false);
            $table->string('slug')->unique()->comment('URL-friendly unique identifier for the company.');
            $table->string('description')->nullable()->comment('Short public description of the company or cluster.');
            $table->string('name')->comment('Trading or commercial name of the company.');
            $table->string('rfc')->nullable()->comment('Mexican tax ID (RFC) of the company.');
            $table->string('legal_name')->nullable()->comment('Full legal/fiscal name as registered with SAT.');
            $table->string('industry')->nullable()->comment('Business sector or industry classification.');
            $table->boolean('is_verified')->default(false)->comment('Whether the company identity has been verified by the platform.');
            $table->integer('employee_count')->nullable()->comment('Approximate number of employees (informational).');

        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('company');
        Schema::enableForeignKeyConstraints();
    }
};
