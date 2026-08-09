<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Schema::table('address', function (Blueprint $table) {
        // $table->renameColumn('zip', 'zipcode');
        // $table->uuid('state_id')->nullable()->change();
        // $table->uuid('municipality_id')->nullable()->change();
        // });

        // Schema::table('user', function (Blueprint $table) {
        // $table->dropColumn(['address']);
        // $table->dropColumn(['postal_code']);
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::table('address', function (Blueprint $table) {
        // $table->renameColumn('zipcode', 'zip');
        /*   $table->uuid('state_id')->nullable(false)->change();
            $table->uuid('municipality_id')->nullable(false)->change(); */
        // });

        // Schema::table('user', function (Blueprint $table) {
        // $table->string('address')->nullable();
        // $table->string('postal_code')->nullable();
        // });
    }
};
