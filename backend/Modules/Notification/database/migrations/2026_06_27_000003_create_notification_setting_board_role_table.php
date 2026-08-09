<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_setting_board_role', function (Blueprint $table): void {
            $table->comment('Pivot linking a tenant notification setting to the board roles that should receive it (tenant-scoped). Only applies to events with audience=board.');
            BaseModel::columns($table, true);
            $table->foreignUuid('notification_setting_id')
                ->references('id')
                ->on('notification_setting')
                ->onDelete('cascade')
                ->comment('FK to notification_setting (the cluster-level event configuration).');
            $table->foreignUuid('board_role_id')
                ->references('id')
                ->on('board_role')
                ->onDelete('cascade')
                ->comment('FK to board_role (central catalog) identifying which board role receives this notification.');
            $table->unique(['notification_setting_id', 'board_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_setting_board_role');
    }
};
