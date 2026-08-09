<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 12 (§16.3.1 / §16.3.2): add contact + visual-identity fields to company.
 *
 * - phone / email / website: general organization contact info (Información general).
 * - short_name: "Nombre corto" shown in notifications (Branding).
 * - tagline: "Lema" (Branding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('fiscal_address')->comment('General contact phone number for the organization.');
            $table->string('email')->nullable()->after('phone')->comment('General contact email for the organization.');
            $table->string('website')->nullable()->after('email')->comment('Public website URL of the organization or company.');
            $table->string('short_name')->nullable()->after('website')->comment('Short display name shown in notifications and space-constrained UI.');
            $table->string('tagline')->nullable()->after('short_name')->comment('Branding tagline (lema) displayed in the resident app.');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'email', 'website', 'short_name', 'tagline']);
        });
    }
};
