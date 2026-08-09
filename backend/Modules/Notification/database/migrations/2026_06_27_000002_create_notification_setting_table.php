<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_setting', function (Blueprint $table): void {
            $table->comment('Per-cluster configuration of each notification event (tenant-scoped). One row per tenant per event, seeded by TenantNotificationSettingSeeder on tenant creation.');
            BaseModel::columns($table, true);
            $table->foreignUuid('notification_event_id')
                ->references('id')
                ->on('notification_event')
                ->onDelete('cascade')
                ->comment('FK to notification_event (central catalog) identifying which event this setting controls.');
            $table->boolean('is_active')->default(true)->comment('Master toggle: when false this event produces no notifications for this cluster regardless of channel settings.');
            $table->boolean('channel_email')->default(true)->comment('Whether the email (blade_email) channel is enabled for this event in this cluster.');
            $table->boolean('channel_push')->default(true)->comment('Whether the push notification (firebase_push) channel is enabled for this event in this cluster.');
            $table->boolean('channel_whatsapp')->default(false)->comment('Whether the WhatsApp channel is enabled for this event in this cluster (placeholder; no-op until provider configured).');
            $table->unique(['tenant_id', 'notification_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_setting');
    }
};
