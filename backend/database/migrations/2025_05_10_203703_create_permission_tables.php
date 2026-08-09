<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names') ?? [
            'permissions' => 'permissions',
            'roles' => 'roles',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
        ];

        // PostgreSQL: DROP ... CASCADE to force drop even when dependents exist.
        $tables = [
            $tableNames['role_has_permissions'],
            $tableNames['model_has_permissions'],
            $tableNames['model_has_roles'],
            $tableNames['roles'],
            $tableNames['permissions'],
        ];
        foreach ($tables as $table) {
            DB::statement("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
        }
        // Legacy table names (in case they exist).
        foreach (['permission_role', 'permission_user', 'role_user', 'role'] as $legacy) {
            DB::statement("DROP TABLE IF EXISTS \"{$legacy}\" CASCADE");
        }

        $teams = config('permission.teams');
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

        if (empty($tableNames)) {
            throw new Exception('Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }
        if ($teams && empty($columnNames['team_foreign_key'] ?? null)) {
            throw new Exception('Error: team_foreign_key on config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }

        Schema::create($tableNames['permissions'], static function (Blueprint $table) {
            // $table->engine('InnoDB');
            $table->uuid('uuid')->primary()->unique()->comment('UUID primary key for the permission'); // permission id
            $table->string('name')->comment('Permission name (e.g., "edit articles")');       // For MyISAM use string('name', 225); // (or 166 for InnoDB with Redundant/Compact row format)
            $table->string('guard_name')->comment('Guard this permission belongs to (e.g., "api")'); // For MyISAM use string('guard_name', 25);
            $table->string('description')->nullable()->comment('Optional human-readable description of the permission');
            $table->foreignUuid('module_id')->nullable()->references('id')->on('module')->comment('FK to module (groups the permission under a module)');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create($tableNames['roles'], static function (Blueprint $table) use ($teams, $columnNames) {
            // $table->engine('InnoDB');
            $table->uuid('uuid')->primary()->unique()->comment('UUID primary key for the role'); // role id
            if ($teams || config('permission.testing')) { // permission.testing is a fix for sqlite testing
                $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
                $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');
            }
            $table->softDeletes();
            $table->uuid('user_created_id')->nullable()->comment('FK to user who created this role');
            $table->uuid('user_updated_id')->nullable()->comment('FK to user who last updated this role');
            $table->uuid('user_deleted_id')->nullable()->comment('FK to user who soft-deleted this role');
            $table->uuid('user_restored_id')->nullable()->comment('FK to user who restored this role');
            $table->string('name')->comment('Role name (e.g., "admin")');       // For MyISAM use string('name', 225); // (or 166 for InnoDB with Redundant/Compact row format)
            $table->string('description')->nullable()->comment('Optional human-readable description of the role');
            $table->string('guard_name')->comment('Guard this role belongs to (e.g., "api")'); // For MyISAM use string('guard_name', 25);
            $table->timestamps();
            if ($teams || config('permission.testing')) {
                $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name']);
            } else {
                $table->unique(['name', 'guard_name']);
            }
        });

        Schema::create($tableNames['model_has_permissions'], static function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
            $table->uuid($pivotPermission)->comment('FK to permissions (permission_id)');

            $table->string('model_type')->comment('Morph type of the model that holds this permission');
            $table->uuid($columnNames['model_morph_key'])->comment('UUID of the model that holds this permission');
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                ->references('uuid') // permission id
                ->on($tableNames['permissions']);
            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');

                $table->primary(
                    [$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary'
                );
            } else {
                $table->primary(
                    [$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary'
                );
            }
        });

        Schema::create($tableNames['model_has_roles'], static function (Blueprint $table) use ($columnNames, $pivotRole, $teams) {
            $table->uuid($pivotRole)->comment('FK to roles (role_id)');

            $table->string('model_type')->comment('Morph type of the model that holds this role');
            $table->uuid($columnNames['model_morph_key'])->comment('UUID of the model that holds this role');
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            /* $table->foreign($pivotRole)
                ->references('uuid') // role id
                ->on($tableNames['roles'])->noActionOnDelete(); */
            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');

                $table->primary(
                    [$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary'
                );
            } else {
                $table->primary(
                    [$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary'
                );
            }
        });

        Schema::create($tableNames['role_has_permissions'], static function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
            $table->uuid($pivotPermission)->comment('FK to permissions (permission_id)');
            $table->uuid($pivotRole)->comment('FK to roles (role_id)');

            $table->foreign($pivotPermission)
                ->references('uuid') // permission id
                ->on($tableNames['permissions'])->noActionOnDelete();

            $table->foreign($pivotRole)
                ->references('uuid') // role id
                ->on($tableNames['roles'])->noActionOnDelete();

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
        });

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');

        if (empty($tableNames)) {
            throw new Exception('Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');
        }

        Schema::drop($tableNames['role_has_permissions']);
        Schema::drop($tableNames['model_has_roles']);
        Schema::drop($tableNames['model_has_permissions']);
        Schema::drop($tableNames['roles']);
        Schema::drop($tableNames['permissions']);
    }
};
