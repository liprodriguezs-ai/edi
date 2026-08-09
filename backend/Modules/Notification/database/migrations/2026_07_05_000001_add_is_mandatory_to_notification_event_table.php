<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_event', function (Blueprint $table) {
            $table->boolean('is_mandatory')->default(false)->after('is_active')->comment('true = admin cannot deactivate the event, only its channels; platform policy');
        });
    }

    public function down(): void
    {
        Schema::table('notification_event', function (Blueprint $table) {
            $table->dropColumn('is_mandatory');
        });
    }
};
