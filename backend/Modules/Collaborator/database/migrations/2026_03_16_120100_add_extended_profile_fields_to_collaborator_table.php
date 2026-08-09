<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->uuid('nationality_id')->nullable()->after('company_id')->comment('FK to nationality catalog');
            $table->string('birth_place')->nullable()->after('nationality_id')->comment('City or state of birth');
            $table->uuid('marital_status_id')->nullable()->after('birth_place')->comment('FK to marital_status catalog');
            $table->text('address')->nullable()->after('marital_status_id')->comment('Home address');
            $table->string('official_identification')->nullable()->after('address')->comment('Official ID document type or number');
            $table->string('beneficiary')->nullable()->after('official_identification')->comment('Name of the designated beneficiary');
            $table->uuid('work_shift_id')->nullable()->after('beneficiary')->comment('FK to work_shift catalog');
            $table->string('specific_schedule')->nullable()->after('work_shift_id')->comment('Custom schedule description when shift is non-standard');
        });
    }

    public function down(): void
    {
        Schema::table('collaborator', function (Blueprint $table) {
            $table->dropColumn([
                'nationality_id',
                'birth_place',
                'marital_status_id',
                'address',
                'official_identification',
                'beneficiary',
                'work_shift_id',
                'specific_schedule',
            ]);
        });
    }
};
