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
        Schema::create('permission_role', function (Blueprint $table) {
            BaseModel::columns($table);
            $table->foreignUuid('permission_id')->references('id')->on('permissions')->cascadeOnDelete()->comment('FK to permissions');
            $table->foreignUuid('role_id')->references('id')->on('role')->cascadeOnDelete()->comment('FK to role');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        if (Schema::hasTable('permission_role')) {
            Schema::drop('permission_role');
        }
    }
};
