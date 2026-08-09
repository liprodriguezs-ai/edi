<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('role_user', function (Blueprint $table) {
            $table->id('id');
            $table->foreignUuid('role_id')->comment('FK to role');
            $table->foreignUuid('user_id')->references('id')->on('user')->onDelete('cascade')->comment('FK to user');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        if (Schema::hasTable('role_user')) {
            Schema::drop('role_user');
        }
    }
};
