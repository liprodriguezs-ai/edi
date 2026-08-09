<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

/**
 * notification_preference (CENTRAL — no tenant_id, one row per user):
 *
 * Holds each user's notification delivery preferences. Preferences belong to the
 * USER (not the cluster), so the table is central — exactly one row per user.
 *
 * `channels` is a JSON map of category => { push:bool, email:bool, sms:bool }.
 * A missing channel in the map (or a missing row entirely) means "enabled" — so the
 * default behavior is everything-on, which keeps existing notification flows unchanged.
 *
 * quiet_hours_start/end (nullable time) suppress NON-urgent push during that window.
 * When both are null there are no quiet hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preference', function (Blueprint $table) {
            BaseModel::columns($table, false);
            $table->uuid('user_id')->comment('FK to user (one row per user; bare UUID, no FK constraint)');
            $table->json('channels')->nullable()->comment('JSON map of notification category => {push, email, sms} booleans; null means all enabled');
            $table->time('quiet_hours_start')->nullable()->comment('Start of quiet hours window (suppress non-urgent push); null = no quiet hours');
            $table->time('quiet_hours_end')->nullable()->comment('End of quiet hours window; null = no quiet hours');

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preference');
    }
};
