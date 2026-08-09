<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->string('constitutive_deed_number')->nullable()->after('fiscal_address')->comment('Number of the constitutive deed (número de escritura).');
            $table->date('constitutive_deed_date')->nullable()->after('constitutive_deed_number')->comment('Date the constitutive deed was signed.');
            $table->string('constitutive_notary_number')->nullable()->after('constitutive_deed_date')->comment('Notary office number that issued the deed.');
            $table->string('constitutive_notary_name')->nullable()->after('constitutive_notary_number')->comment('Full name of the notary public who issued the deed.');
            $table->string('constitutive_notary_state')->nullable()->after('constitutive_notary_name')->comment('Mexican state where the notary office is located.');
        });
    }

    public function down(): void
    {
        Schema::table('company', function (Blueprint $table) {
            $table->dropColumn([
                'constitutive_deed_number',
                'constitutive_deed_date',
                'constitutive_notary_number',
                'constitutive_notary_name',
                'constitutive_notary_state',
            ]);
        });
    }
};
