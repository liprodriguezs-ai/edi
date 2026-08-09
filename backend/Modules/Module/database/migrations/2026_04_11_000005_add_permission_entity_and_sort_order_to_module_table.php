<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module', function (Blueprint $table) {
            $table->string('permission_entity')->nullable()->after('route')->comment('Permission key used to derive CRUD permission names (e.g. "client" → client.read, client.create …)');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('permission_entity')->comment('Display order within the sidebar group (ascending)');
        });
    }

    public function down(): void
    {
        Schema::table('module', function (Blueprint $table) {
            $table->dropColumn(['permission_entity', 'sort_order']);
        });
    }
};
