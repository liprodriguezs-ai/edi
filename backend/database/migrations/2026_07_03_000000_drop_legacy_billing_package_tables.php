<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use QCH\Core\Models\BaseModel;

/**
 * Billing v2 cleanup (S5-C3): retire the legacy banded catalog entirely.
 *
 * The public pricing catalog + quoter now run exclusively on the plan×module
 * matrix (plan_module_price via PlanPricingService). The legacy PricingService,
 * its billing_package_price / billing_package_module tables, the legacy
 * billing_package rows (base / base_preferential / package / amenities) and the
 * legacy type / pricing_type columns are no longer read by anything. This drops
 * them, ordered so referencing rows/tables go before their parents.
 */
return new class extends Migration
{
    /** Legacy v1 module-as-package keys seeded by the removed BillingPackageSeeder. */
    private const LEGACY_KEYS = ['base', 'base_preferential', 'package', 'amenities'];

    public function up(): void
    {
        $legacyPackageIds = DB::table('billing_package')
            ->whereIn('key', self::LEGACY_KEYS)
            ->pluck('id')
            ->all();

        // 1. Remove the legacy pricing / module-inclusion rows first (they FK to
        //    billing_package), then the legacy packages themselves.
        if ($legacyPackageIds !== []) {
            DB::table('billing_package_price')->whereIn('billing_package_id', $legacyPackageIds)->delete();
            DB::table('billing_package_module')->whereIn('billing_package_id', $legacyPackageIds)->delete();
        }

        DB::table('billing_package')->whereIn('key', self::LEGACY_KEYS)->delete();

        // 2. Drop the now-empty legacy tables.
        Schema::dropIfExists('billing_package_price');
        Schema::dropIfExists('billing_package_module');

        // 3. Drop the legacy discriminator columns from billing_package (v2 plans
        //    never read them).
        Schema::table('billing_package', function (Blueprint $table) {
            $table->dropColumn(['type', 'pricing_type']);
        });
    }

    public function down(): void
    {
        // Restore the legacy columns (structural rollback; legacy rows are NOT
        // re-seeded). Given billing_package now holds v2 plan rows, re-add with a
        // sane default so the NOT-NULL-equivalent shape does not fail on them.
        Schema::table('billing_package', function (Blueprint $table) {
            $table->string('type')->default('base')->comment('Package role: base (required core plan) | addon (optional extra)');
            $table->string('pricing_type')->default('fixed')->comment('How the package is priced: per_unit (multiplied by units) | fixed (flat amount)');
        });

        Schema::create('billing_package_price', function (Blueprint $table) {
            $table->comment('Per-interval pricing for a billing package (one row per month/year), including optional usage tiers and Stripe references');
            BaseModel::columns($table, false);

            $table->uuid('billing_package_id')->comment('FK to billing_package');
            $table->string('interval')->comment('Billing interval this price applies to: month | year');
            $table->bigInteger('unit_amount')->comment('Base price in minor units (cents); per_unit packages multiply this by units, fixed packages charge it flat, banded packages use it only as a fallback');
            $table->string('currency', 3)->default('MXN')->comment('ISO 4217 currency code (uppercase)');
            $table->string('tier_mode')->nullable()->comment('Tiered pricing strategy when tiers is set: flat | graduated | volume | banded (null = no tiers)');
            $table->json('tiers')->nullable()->comment('JSON array of pricing tiers [{up_to:int|null, unit_amount:int, flat_amount?:int, label?:string, proposed?:bool}]; up_to=null means the unbounded top tier. banded mode uses flat_amount (fixed tariff per band) + unit_amount as per-unit overage above the previous band ceiling');
            $table->string('stripe_price_id')->nullable()->comment('Stripe price ID linked to this interval (Phase 2 checkout)');
            $table->string('stripe_product_id')->nullable()->comment('Stripe product ID linked to this package (Phase 2 checkout)');
            $table->boolean('is_free')->default(false)->comment('Whether this interval is offered at no cost');
            $table->boolean('is_active')->default(true)->comment('Whether this price is published and quotable');
            $table->boolean('is_public')->default(true)->comment('Whether the price is shown in the public pricing catalog; false = preferential price visible only to super-admin.');

            $table->foreign('billing_package_id')->references('id')->on('billing_package')->cascadeOnDelete();
            $table->unique(['billing_package_id', 'interval']);
        });

        Schema::create('billing_package_module', function (Blueprint $table) {
            $table->comment('Pivot mapping which platform modules each billing package includes (for the "what is included" display in the catalog)');

            // UUID PK with a DB-level default so attach()/sync() work (laravel-backend-architecture skill §2.9).
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            } else {
                $table->uuid('id')->primary()->default(DB::raw('(lower(hex(randomblob(16))))'));
            }

            $table->uuid('billing_package_id')->comment('FK to billing_package');
            $table->uuid('module_id')->comment('FK to module');
            $table->timestamps();

            $table->foreign('billing_package_id')->references('id')->on('billing_package')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('module')->cascadeOnDelete();
            $table->unique(['billing_package_id', 'module_id']);
        });
    }
};
