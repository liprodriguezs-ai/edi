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
        Schema::create('notification', function (Blueprint $table) {
            BaseModel::columns($table, true);
            $table->boolean('system')->nullable()->default(0)->comment('Whether this is a system-generated notification (true) or admin-authored (false)');
            $table->text('title')->comment('Notification title shown in the push alert and in-app list');
            $table->longText('message')->comment('Full notification body text');
            $table->string('status')->default('pending')->comment('Dispatch status: pending | sent | failed');
            $table->text('click_action')->nullable()->comment('Deep-link URL opened when the user taps the notification');
            $table->boolean('repeat')->default(false)->nullable()->comment('Whether the notification should be re-sent on a recurring schedule');
            $table->timestamp('date')->useCurrent()->nullable()->comment('Scheduled send date/time; defaults to now');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification');
    }
};
