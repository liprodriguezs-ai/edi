<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider', function (Blueprint $table) {
            BaseModel::columns($table, true);

            $table->uuid('provider_type_id')->comment('FK to provider_type');
            $table->foreign('provider_type_id')->references('id')->on('provider_type');

            $table->string('name')->comment('Legal or trade name of the provider');
            $table->string('rfc')->nullable()->comment('Mexican tax ID (RFC) of the provider; unique per tenant when set');

            $table->uuid('provider_service_type_id')->nullable()->comment('FK to provider_service_type; nullable when service type is unclassified');
            $table->foreign('provider_service_type_id')->references('id')->on('provider_service_type')->nullOnDelete();

            $table->string('contact_person')->nullable()->comment('Name of the primary contact at this provider');
            $table->string('phone')->nullable()->comment('Contact phone number');
            $table->string('email')->nullable()->comment('Contact email address');
            $table->text('address')->nullable()->comment('Physical address of the provider');

            $table->boolean('is_recurring')->default(false)->comment('Whether this provider generates recurring periodic expenses');
            // frequency: monthly / bimonthly / eventual (stored as string; no enum to remain flexible)
            $table->string('frequency')->nullable()->comment('Recurrence frequency: monthly | bimonthly | eventual');

            $table->bigInteger('estimated_amount')->nullable()->comment('Minor units (cents); estimated recurring payment amount'); // minor units (MXN cents)

            $table->smallInteger('usual_payment_day')->nullable()->comment('Day of month (1–31) when recurring payment is typically due'); // 1–31

            $table->string('clabe')->nullable()->comment('Mexican CLABE interbank account number for wire transfers');
            $table->string('bank')->nullable()->comment('Name of the provider\'s bank');
            $table->text('notes')->nullable()->comment('Internal notes about this provider');

            $table->boolean('is_active')->default(true)->comment('Whether this provider is currently active and selectable');
        });

        // Unique: tenant + name (active rows only).
        // RFC when not null is also unique per tenant via COALESCE expression.
        DB::statement(
            'CREATE UNIQUE INDEX provider_tenant_name_unique ON "provider" '
            .'(tenant_id, name) '
            .'WHERE deleted_at IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX provider_tenant_rfc_unique ON "provider" '
            ."(tenant_id, COALESCE(rfc, '')) "
            .'WHERE deleted_at IS NULL AND rfc IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS provider_tenant_name_unique');
        DB::statement('DROP INDEX IF EXISTS provider_tenant_rfc_unique');
        Schema::dropIfExists('provider');
    }
};
