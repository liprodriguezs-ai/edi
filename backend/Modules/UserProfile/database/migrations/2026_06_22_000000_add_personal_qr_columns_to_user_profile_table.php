<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plan 048: personal rotating "Mi QR" for residents/renters. Each profile holds
     * one short-lived entry credential that auto-rotates every 60s, regenerated
     * lazily on read (no cron). The guard scans it like a visit QR.
     */
    public function up(): void
    {
        Schema::table('user_profile', function (Blueprint $table): void {
            $table->string('personal_qr_token', 64)->nullable()->unique()
                ->comment('Live rotating personal entry credential (RES- prefixed); regenerated every 60s on read. Treat as a secret. UNIQUE so the app-level generation loop has a DB-level atomicity guarantee.');
            $table->timestamp('personal_qr_expires_at')->nullable()
                ->comment('UTC expiry of personal_qr_token; when past, the next /access/my-qr read rotates the token.');
        });
    }

    public function down(): void
    {
        Schema::table('user_profile', function (Blueprint $table): void {
            $table->dropColumn(['personal_qr_token', 'personal_qr_expires_at']);
        });
    }
};
