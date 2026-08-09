<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align package_carrier.order / package_type.order with the canonical tower.order
     * contract (nullable=YES, default 0). The catalog admin UI submits order=null for a
     * blank "Orden de aparición" field; without nullable the insert hit a NOT NULL
     * violation (the create flow broke on its default form state).
     */
    public function up(): void
    {
        Schema::table('package_carrier', function (Blueprint $table) {
            $table->integer('order')->default(0)->nullable()->comment('Orden de aparición en el listado')->change();
        });

        Schema::table('package_type', function (Blueprint $table) {
            $table->integer('order')->default(0)->nullable()->comment('Orden de aparición en el listado')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_carrier', function (Blueprint $table) {
            $table->integer('order')->default(0)->nullable(false)->comment('Orden de aparición en el listado')->change();
        });

        Schema::table('package_type', function (Blueprint $table) {
            $table->integer('order')->default(0)->nullable(false)->comment('Orden de aparición en el listado')->change();
        });
    }
};
