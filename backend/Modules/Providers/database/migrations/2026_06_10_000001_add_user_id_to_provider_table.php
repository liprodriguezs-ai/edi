<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §10.4.1 (providers-07) — a provider may optionally be linked to a person
 * (user) of the cluster, e.g. the gardener who is also registered as staff,
 * enabling the §10.5 flow (provider payment reaching a known person).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('provider_service_type_id')->comment('FK to user; optional link to a cluster person (e.g. staff registered as a provider)');
            $table->foreign('user_id')->references('id')->on('user')->nullOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('provider', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
