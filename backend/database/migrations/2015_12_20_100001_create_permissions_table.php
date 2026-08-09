<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            BaseModel::columns($table);
            $table->string('name', 50)->comment('Human-readable permission name');
            $table->string('slug')->unique()->comment('Machine-readable unique permission slug');
            $table->string('resource', 20)->default('System')->comment('Resource/module this permission applies to');
            $table->boolean('system')->default(0)->comment('Whether this is a system-reserved permission (cannot be deleted)');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            Schema::drop('permissions');
        }
    }
};
