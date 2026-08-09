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
        Schema::create('notification_user', function (Blueprint $table) {
            BaseModel::columns($table, true);
            $table->boolean('is_read')->comment('Whether the recipient has read this notification');
            $table->index(['user_id', 'notification_id']);
            $table->uuid('user_id')->nullable()->comment('FK to user (recipient); nullable to allow soft-delete safety');
            $table->foreignUuid('notification_id')->references('id')->on('notification')->onDelete('cascade')->comment('FK to notification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_user');
    }
};
