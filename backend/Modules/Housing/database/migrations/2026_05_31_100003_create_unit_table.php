<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit', function (Blueprint $table) {
            $table->comment('Individual residential unit (house or apartment) within the cluster');
            BaseModel::columns($table, true);
            $table->foreignUuid('unit_type_id')->constrained('unit_type')->cascadeOnDelete()->comment('FK to unit_type');
            $table->string('number')->nullable()->comment('Unit identifier number or letter within the tower/floor');
            $table->foreignUuid('tower_id')->nullable()->constrained('tower')->nullOnDelete()->comment('FK to tower — null for horizontal clusters');
            $table->string('floor')->nullable()->comment('Floor level identifier (e.g. 1, PB, Penthouse)');
            $table->foreignUuid('section_id')->nullable()->constrained('section')->nullOnDelete()->comment('FK to section — optional zone grouping');
            $table->decimal('built_m2', 9, 2)->nullable()->comment('Built surface area in square meters');
            $table->decimal('land_m2', 9, 2)->nullable()->comment('Land surface area in square meters');
            $table->decimal('ownership_share', 7, 4)->nullable()->comment('Co-ownership percentage (indiviso) used for proportional charges');
            $table->foreignUuid('unit_status_id')->constrained('unit_status')->cascadeOnDelete()->comment('FK to unit_status — current occupancy/physical status');
            $table->text('notes')->nullable()->comment('Free-form notes about the unit');
        });

        // A plain UNIQUE(...) is insufficient on Postgres: NULL is distinct from
        // NULL, so two houses (tower_id/floor NULL) with the same number would NOT
        // collide — violating "unit identity is unique per cluster". A COALESCE
        // expression index makes NULLs comparable and scopes to live rows only.
        DB::statement(
            'CREATE UNIQUE INDEX unit_identity_unique ON "unit" '
            ."(tenant_id, unit_type_id, COALESCE(tower_id::text, ''), COALESCE(floor, ''), COALESCE(number, '')) "
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unit_identity_unique');
        Schema::dropIfExists('unit');
    }
};
