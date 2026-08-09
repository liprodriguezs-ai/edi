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
        // Create without the self-referential FK first; Postgres cannot verify the FK
        // against the referenced table while the table is still being built.
        Schema::create('expense_category', function (Blueprint $table) {
            $table->comment('Tenant-scoped hierarchical expense categories for classifying cluster expenditures.');
            BaseModel::columns($table, true);
            $table->string('name')->comment('Category name (unique per tenant+parent combination).');
            $table->uuid('parent_id')->nullable()->comment('FK to expense_category; null for top-level categories.');
            $table->boolean('is_active')->default(true)->comment('Whether this category is available for new expenses.');
            $table->integer('order')->default(0)->comment('Display sort order among sibling categories.');
        });

        // Add self-referential FK after the table (and its primary key) exist.
        Schema::table('expense_category', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('expense_category')
                ->nullOnDelete();
        });

        // Unique over nullable parent_id: plain UNIQUE treats each NULL as distinct on Postgres.
        // COALESCE(parent_id::text, '') makes NULLs comparable so two top-level categories
        // under the same tenant can't share a name. Scoped to live rows only.
        DB::statement(
            'CREATE UNIQUE INDEX expense_category_tenant_parent_name_unique ON "expense_category" '
            ."(tenant_id, COALESCE(parent_id::text, ''), name) "
            .'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS expense_category_tenant_parent_name_unique');
        Schema::dropIfExists('expense_category');
    }
};
