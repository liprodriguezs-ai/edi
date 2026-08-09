<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->string('legal_representative')->nullable()->after('legal_name')->comment('Full name of the legal representative (representante legal).');
            $table->string('employer_registration')->nullable()->after('legal_representative')->comment('IMSS employer registration number (Registro Patronal).');
            $table->text('fiscal_address')->nullable()->after('employer_registration')->comment('Full fiscal/registered address of the company.');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn([
                'legal_representative',
                'employer_registration',
                'fiscal_address',
            ]);
        });
    }
};
