<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Discount / condonación (spec §8.2 — President/Admin only): a charge's owed
     * amount can be reduced WITHOUT a payment. The original expected_amount stays
     * visible; discount_amount holds the condoned minor units and discount_reason
     * the justification. discounted_by_user_id references the central user table
     * (no FK — matches the audit-actor column convention).
     */
    public function up(): void
    {
        Schema::table('charge', function (Blueprint $table) {
            $table->bigInteger('discount_amount')->default(0)->after('paid_amount')
                ->comment('Minor units condoned (spec §8.2). effective_expected = expected_amount - discount_amount.');
            $table->text('discount_reason')->nullable()->after('discount_amount')
                ->comment('Justification for the condonación (spec §8.2); required when discount_amount > 0.');
            $table->uuid('discounted_by_user_id')->nullable()->after('discount_reason')
                ->comment('Central user who applied the discount (no FK — actor column convention).');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('charge', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'discount_reason', 'discounted_by_user_id']);
        });
    }
};
