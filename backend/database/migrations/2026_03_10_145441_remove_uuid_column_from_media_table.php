<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('media', 'uuid')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('uuid');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('media', 'uuid')) {
            return;
        }

        Schema::table('media', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->unique()->after('model_id');
        });
    }
};
