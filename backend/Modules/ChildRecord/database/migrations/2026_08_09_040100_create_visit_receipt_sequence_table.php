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
     * Per-tenant, per-year counter backing visit.receipt_number generation
     * (D9). Consumed only by VisitService under lockForUpdate(); rows are
     * never soft-deleted, so a plain unique is enough.
     */
    public function up(): void
    {
        Schema::create('visit_receipt_sequence', function (Blueprint $table) {
            $table->comment('Per-tenant, per-year counter backing visit receipt_number generation');
            BaseModel::columns($table, true);
            $table->smallInteger('year')->comment('Calendar year this counter tracks (matches visit_date year)');
            $table->integer('last_number')->default(0)->comment('Last sequence number issued for this tenant/year');
            $table->unique(['tenant_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_receipt_sequence');
    }
};
