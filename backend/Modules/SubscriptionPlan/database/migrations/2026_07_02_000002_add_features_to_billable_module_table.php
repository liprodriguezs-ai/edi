<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billable_module', function (Blueprint $table) {
            $table->json('features')->nullable()->after('description')
                ->comment('Ordered list of user-facing feature strings (Spanish) shown under the module in pricing UIs; null or [] tolerated');
        });
    }

    public function down(): void
    {
        Schema::table('billable_module', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }
};
