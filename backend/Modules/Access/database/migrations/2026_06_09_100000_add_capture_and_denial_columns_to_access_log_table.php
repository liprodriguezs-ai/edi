<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §14.4.4 manual capture (identification, plate, motive) + §14.5 r7 blacklist
     * denial trail. entered_at becomes nullable: a DENIED attempt is recorded in the
     * immutable log (denied_at + denied_reason) without ever stamping an entry.
     */
    public function up(): void
    {
        Schema::table('access_log', function (Blueprint $table): void {
            $table->string('id_document_type', 50)->nullable()->comment('Type of identification document presented at the gate (e.g. INE, Passport).');
            $table->string('id_document_number', 100)->nullable()->comment('Document number of the identification presented at the gate.');
            $table->string('plate', 20)->nullable()->comment('Vehicle licence plate captured at the gate for unregistered vehicle entries.');
            $table->foreignUuid('visitor_motive_id')->nullable()->constrained('visitor_motive')->comment('FK to visitor_motive — reason for access captured at the gate.');
            $table->timestamp('denied_at')->nullable()->comment('Timestamp when this access attempt was denied; null for allowed entries.');
            $table->string('denied_reason')->nullable()->comment('Reason the access attempt was denied.');
        });

        Schema::table('access_log', function (Blueprint $table): void {
            $table->timestamp('entered_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access_log', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('visitor_motive_id');
            $table->dropColumn(['id_document_type', 'id_document_number', 'plate', 'denied_at', 'denied_reason']);
        });
    }
};
