<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE module_system ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE system_tenant ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE subscription_plan_system ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE module_system ALTER COLUMN id DROP DEFAULT');
        DB::statement('ALTER TABLE system_tenant ALTER COLUMN id DROP DEFAULT');
        DB::statement('ALTER TABLE subscription_plan_system ALTER COLUMN id DROP DEFAULT');
    }
};
