<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_event', function (Blueprint $table): void {
            $table->comment('Central catalog of all notifiable events in EDI (global, not tenant-scoped). Seeded once by CatalogSeeder; read-only for organization roles.');
            BaseModel::columns($table, false);
            $table->string('key')->unique()->comment('Machine-readable event slug used in code (e.g. payment_due_reminder).');
            $table->string('name')->comment('Human-readable event name shown in the admin notification settings UI.');
            $table->string('description')->nullable()->comment('Optional description of when this event fires and who receives it.');
            $table->string('category')->comment('Notification category matching NotificationCategory constants (e.g. collections, access).');
            $table->string('audience')->comment('Primary recipient audience: resident or board.');
            $table->boolean('supports_email')->default(true)->comment('Whether email delivery is available as a channel option for this event.');
            $table->boolean('supports_push')->default(true)->comment('Whether push notification delivery is available as a channel option for this event.');
            $table->boolean('supports_whatsapp')->default(true)->comment('Whether WhatsApp delivery is available as a channel option for this event (no-op until provider is configured).');
            $table->json('default_channels')->comment('Default enabled delivery channels as JSON array (e.g. ["log","firebase_push"]).');
            $table->integer('order')->default(0)->comment('Display sort order in the notification configuration UI.');
            $table->boolean('is_active')->default(true)->comment('Whether this event type is currently active in the system.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_event');
    }
};
