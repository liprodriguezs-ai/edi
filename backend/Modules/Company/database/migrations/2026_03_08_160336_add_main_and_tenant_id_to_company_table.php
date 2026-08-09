<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->boolean('main')->default(false)->after('id')->comment('True for the primary company record of a tenant cluster.');
            $table->uuid('tenant_id')->nullable()->after('main')->comment('FK to tenant; null for non-tenant-scoped (platform) companies.');
            $table->index('main');
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn(['main', 'tenant_id']);
        });
    }
};
