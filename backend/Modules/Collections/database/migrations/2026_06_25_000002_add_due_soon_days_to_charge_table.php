<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charge', function (Blueprint $table): void {
            $table->smallInteger('due_soon_days')
                ->nullable()
                ->after('grace_days')
                ->comment('Snapshot from charge_policy.due_soon_days at generation time. Null = use cluster setting (collections.due_soon_days) in the FSM.');
        });
    }

    public function down(): void
    {
        Schema::table('charge', function (Blueprint $table): void {
            $table->dropColumn('due_soon_days');
        });
    }
};
