<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_event', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_mandatory')->comment('true = system event, not shown in the tenant config UI; still fires with fixed behavior');
        });
    }

    public function down(): void
    {
        Schema::table('notification_event', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
