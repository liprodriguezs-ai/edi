<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement')) {
            return;
        }

        Schema::create('announcement', function (Blueprint $table): void {
            $table->comment('Cluster announcement dispatched via one or more notification channels.');
            BaseModel::columns($table, true);
            $table->string('title')->comment('Announcement headline shown in notification and board views.');
            $table->uuid('announcement_type_id')->comment('FK to announcement_type.');
            $table->uuid('announcement_priority_id')->comment('FK to announcement_priority.');
            $table->uuid('announcement_status_id')->comment('FK to announcement_status.');
            $table->text('content')->comment('Full body text of the announcement.');
            // Target scoping (reuses TargetResolver pattern)
            $table->string('target_type')->default('all')->comment('Audience scope: all, by_type, by_tower, by_floor, etc.');
            $table->jsonb('target_selector')->nullable()->comment('JSON payload that parameterises target_type filtering.');
            // Channels
            $table->boolean('channel_push')->default(true)->comment('Whether to dispatch a push notification for this announcement.');
            $table->boolean('channel_board')->default(true)->comment('Whether to display this announcement on the in-app board.');
            $table->boolean('channel_email')->default(false)->comment('Whether to send this announcement via email.');
            $table->boolean('channel_sms')->default(false)->comment('Whether to send this announcement via SMS.');
            // Scheduling & lifecycle
            $table->timestamp('publish_at')->nullable()->comment('Scheduled publish datetime; null means publish immediately on creation.');
            $table->date('expires_at')->nullable()->comment('Date after which the announcement is no longer shown; null means no expiry.');
            $table->timestamp('edited_at')->nullable()->comment('Timestamp when the announcement content was last edited after publishing.');
            // Author (central user — bypasses UserTenantScope)
            $table->uuid('author_user_id')->nullable()->comment('FK to user who created the announcement (central table; bypasses UserTenantScope).');

            $table->foreign('announcement_type_id')->references('id')->on('announcement_type');
            $table->foreign('announcement_priority_id')->references('id')->on('announcement_priority');
            $table->foreign('announcement_status_id')->references('id')->on('announcement_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement');
    }
};
