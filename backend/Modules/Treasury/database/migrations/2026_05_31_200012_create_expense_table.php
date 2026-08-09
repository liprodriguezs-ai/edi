<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense', function (Blueprint $table) {
            $table->comment('Cluster expense record; beneficiary can be an employee, provider, or free-text other.');
            BaseModel::columns($table, true);

            $table->string('concept')->comment('Short label describing what the expense was for.');
            $table->text('description')->nullable()->comment('Optional extended description of the expense.');
            $table->date('paid_at')->comment('Date the expense was paid.');
            $table->bigInteger('amount')->comment('Minor units (cents); expense amount in the account currency.');

            $table->uuid('expense_category_id')->comment('FK to expense_category.');
            $table->foreign('expense_category_id')->references('id')->on('expense_category');

            $table->uuid('beneficiary_type_id')->comment('FK to beneficiary_type (employee, provider, or other).');
            $table->foreign('beneficiary_type_id')->references('id')->on('beneficiary_type');

            // Nullable — only one of employee_id / provider_id will be set.
            $table->uuid('employee_id')->nullable()->comment('FK to collaborator; set only when beneficiary_type is employee.');
            $table->foreign('employee_id')->references('id')->on('collaborator')->nullOnDelete();

            $table->uuid('provider_id')->nullable()->comment('FK to provider; set only when beneficiary_type is provider.');
            $table->foreign('provider_id')->references('id')->on('provider')->nullOnDelete();

            $table->string('beneficiary_text')->nullable()->comment('Free-text beneficiary name used when beneficiary_type is other.');

            // Period fields
            $table->smallInteger('year')->comment('Calendar year the expense belongs to.');
            $table->smallInteger('month')->nullable()->comment('Calendar month (1-12) the expense belongs to; nullable for yearly expenses.');
            $table->smallInteger('fortnight')->nullable()->comment('Fortnight within the month (1 or 2); nullable when not applicable.');

            $table->uuid('payment_method_id')->comment('FK to payment_method used to pay this expense.');
            $table->foreign('payment_method_id')->references('id')->on('payment_method');

            $table->uuid('bank_account_id')->comment('FK to bank_account debited for this expense.');
            $table->foreign('bank_account_id')->references('id')->on('bank_account');

            $table->string('reference')->nullable()->comment('External reference number (e.g. invoice or receipt number).');

            // community_project_id: nullable UUID — NO FK constraint yet (Projects module is future).
            // The Projects module will add: ALTER TABLE expense ADD CONSTRAINT fk_expense_project...
            $table->uuid('community_project_id')->nullable()->index()->comment('Reference to community_project; no FK enforced until the Projects module is deployed.');

            // authorized_by_user_id — central user table; no global scope bypassing needed at schema level.
            $table->uuid('authorized_by_user_id')->nullable()->index()->comment('FK to user who authorised the expense; central table, bypasses tenant scope.');

            $table->uuid('expense_status_id')->comment('FK to expense_status.');
            $table->foreign('expense_status_id')->references('id')->on('expense_status');

            $table->text('void_reason')->nullable()->comment('Reason provided when the expense is voided.');
            $table->text('notes')->nullable()->comment('Optional administrative notes about the expense.');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense');
    }
};
