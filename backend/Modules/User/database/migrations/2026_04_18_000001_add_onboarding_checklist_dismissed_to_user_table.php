<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->boolean('onboarding_checklist_dismissed')->default(false)->after('onboarding')->comment('Whether the user has dismissed the onboarding checklist widget.');
        });
    }

    public function down(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('onboarding_checklist_dismissed');
        });
    }
};
