<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §16.3.8 / configuration-08 — database backstops for legal-document versioning:
 *
 *  1. Partial unique index: at most ONE is_current row per (tenant_id, type)
 *     among non-deleted rows. Two concurrent uploads can no longer both end up
 *     current — the loser hits a 23505 unique violation.
 *  2. Plain unique on (tenant_id, type, version): no duplicate version numbers
 *     for the same document type within a tenant.
 *
 * Partial-index syntax is supported by both PostgreSQL (primary) and SQLite
 * (testing fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX legal_document_one_current_per_type '
            .'ON legal_document (tenant_id, type) '
            .'WHERE is_current AND deleted_at IS NULL'
        );

        Schema::table('legal_document', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('legal_document', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'type', 'version']);
        });

        DB::statement('DROP INDEX IF EXISTS legal_document_one_current_per_type');
    }
};
