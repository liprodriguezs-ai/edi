<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §14.4.4: identification captured at the gate for entry-request visitors
     * (pending remote approval) so the resident's push can show "INE: ****1234".
     */
    public function up(): void
    {
        Schema::table('visitor', function (Blueprint $table): void {
            $table->string('id_document_type', 50)->nullable()->comment('Type of identification document presented at the gate (e.g. INE, Passport).');
            $table->string('id_document_number', 100)->nullable()->comment('Document number of the identification presented at the gate.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitor', function (Blueprint $table): void {
            $table->dropColumn(['id_document_type', 'id_document_number']);
        });
    }
};
