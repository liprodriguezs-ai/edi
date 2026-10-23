<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * referral is the concrete referral act of a specialist_referral
     * follow-up (design §3, REQ-FUP-007). N referrals per follow-up are
     * allowed (e.g. neurology AND audiology).
     */
    public function up(): void
    {
        Schema::create('referral', function (Blueprint $table) {
            $table->comment('Concrete referral act of a specialist_referral follow-up');
            BaseModel::columns($table, true);
            $table->uuid('follow_up_id')->index()->comment('FK to follow_up (type specialist_referral only)');
            $table->uuid('referral_specialty_id')->index()->comment('FK to tenant referral_specialty');
            $table->date('referred_at')->comment('Date the child was referred');
            $table->string('institution')->nullable()->comment('Receiving institution/unit');
            $table->text('outcome_notes')->nullable()->comment('Outcome/feedback from the specialist');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral');
    }
};
