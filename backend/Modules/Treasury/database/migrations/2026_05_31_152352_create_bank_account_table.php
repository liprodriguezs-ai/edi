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
        Schema::create('bank_account', function (Blueprint $table) {
            $table->comment('Cluster bank account or cash box; current_balance is recomputed transactionally.');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Display name for this account (unique per tenant).');
            $table->foreignUuid('bank_account_type_id')->constrained('bank_account_type')->cascadeOnDelete()->comment('FK to bank_account_type.');
            $table->foreignUuid('bank_account_purpose_id')->nullable()->constrained('bank_account_purpose')->nullOnDelete()->comment('FK to bank_account_purpose; nullable if no specific purpose assigned.');
            $table->string('bank')->nullable()->comment('Name of the financial institution.');
            $table->string('account_number')->nullable()->comment('Bank account number.');
            $table->string('clabe')->nullable()->comment('CLABE interbank code (unique per tenant when set).');
            $table->string('currency', 3)->default('MXN')->comment('ISO 4217 currency code for this account.');
            $table->bigInteger('initial_balance')->default(0)->comment('Minor units (cents); opening balance when the account was created.');
            $table->bigInteger('current_balance')->default(0)->comment('Minor units (cents); running balance recomputed on every payment or expense.');
            $table->boolean('is_primary')->default(false)->comment('Marks the default account for receiving payments.');
            $table->boolean('is_active')->default(true)->comment('Inactive accounts are hidden from new transaction selection.');
            $table->text('notes')->nullable()->comment('Optional administrative notes about the account.');
            $table->unique(['tenant_id', 'name']);
        });

        // CLABE nullable unique per tenant: a plain UNIQUE treats each NULL as distinct
        // on Postgres, which would allow duplicates. The COALESCE expression + partial
        // WHERE deleted_at IS NULL enforces uniqueness only for non-null CLABEs on live rows.
        DB::statement(
            'CREATE UNIQUE INDEX bank_account_clabe_tenant_unique ON "bank_account" '
            ."(tenant_id, COALESCE(clabe, '')) "
            .'WHERE deleted_at IS NULL AND clabe IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bank_account_clabe_tenant_unique');
        Schema::dropIfExists('bank_account');
    }
};
