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
        Schema::create('role', function (Blueprint $table) {
            BaseModel::columns($table);
            $table->string('name')->comment('Human-readable role name');
            $table->string('slug')->unique()->comment('Machine-readable unique role slug');
            $table->text('description')->nullable()->comment('Optional description of the role\'s purpose');
            $table->boolean('system')->default(0)->comment('Whether this is a system-reserved role (cannot be deleted)');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        if (Schema::hasTable('role')) {
            Schema::drop('role');
        }
    }
};
