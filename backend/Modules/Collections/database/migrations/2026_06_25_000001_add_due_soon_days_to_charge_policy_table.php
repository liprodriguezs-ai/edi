<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charge_policy', function (Blueprint $table): void {
            $table->smallInteger('due_soon_days')
                ->nullable()
                ->after('grace_days')
                ->comment('Days before due_date to mark charge as due_soon. Overrides tenant setting; null = use cluster default (collections.due_soon_days).');
        });
    }

    public function down(): void
    {
        Schema::table('charge_policy', function (Blueprint $table): void {
            $table->dropColumn('due_soon_days');
        });
    }
};
