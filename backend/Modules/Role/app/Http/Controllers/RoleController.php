<?php

namespace Modules\Role\Http\Controllers;

use App\Exceptions\UnprocessableEntityHttpException;
use App\Models\Permission;
use App\Models\Role as SpatieRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Modules\Module\Models\Module;
use Modules\Role\Models\Role;
use Modules\Role\Services\RoleService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class RoleController extends Controller
{
    protected $model = Role::class;

    protected $keyForPermission = 'role';

    public function searchBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name'];
    }

    /**
     * Delegates create to RoleService while keeping Orion's store flow.
     */
    protected function performStore(Request $request, Model $entity, array $attributes): void
    {
        $created = app(RoleService::class)->create($request->all());
        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    public function getPermissions($id)
    {
        $this->authorizePermissionManagement('read.role');

        $role = SpatieRole::findOrFail($id);

        $modules = Module::orderBy('group', 'asc')->orderBy('name', 'asc')->get();
        $rolePermissions = $role->permissions()->pluck('name')->toArray();

        $modulesForReturn = [];

        foreach ($modules as $module) {
            $moduleReturn = $module->toArray();
            $moduleReturn['permissions'] = [];

            foreach ($module->permissions as $permission) {
                $moduleReturn['permissions'][] = [
                    'uuid' => $permission->uuid,
                    'name' => $permission->name,
                    'active' => in_array($permission->name, $rolePermissions),
                ];
            }

            $modulesForReturn[] = $moduleReturn;
        }

        return response()->json(['data' => $modulesForReturn]);
    }

    public function setPermissions($id, Request $request)
    {
        // authz-01 — mutating a GLOBAL role's permission set affects every tenant
        // on the platform. update.role is a super-admin-exclusive permission
        // (RolePermissionTableSeeder), so this gate is super-admin-only in practice.
        $this->authorizePermissionManagement('update.role');

        $role = SpatieRole::findOrFail($id);

        $permissionsData = $request->get('permissions');
        $activePermissionIds = [];

        foreach ($permissionsData as $moduleId => $permissions) {
            foreach ($permissions as $action => $permissionData) {
                if ($permissionData['active']) {
                    $activePermissionIds[] = $permissionData['uuid'];
                }
            }
        }

        $permissionNames = Permission::whereIn('uuid', $activePermissionIds)
            ->pluck('name')
            ->toArray();

        $role->syncPermissions($permissionNames);

        return response()->json(['data' => $activePermissionIds]);
    }

    /**
     * Requires the given token ability (wildcard tokens always pass).
     *
     * @throws AuthorizationException
     */
    private function authorizePermissionManagement(string $ability): void
    {
        $user = auth()->guard('sanctum')->user();

        if ($user && ($user->tokenCan($ability) || $user->tokenCan('*'))) {
            return;
        }

        throw new AuthorizationException('Unauthorized action.');
    }

    /**
     * Prevents deleting a role that has users assigned. Runs inside Orion's destroy flow.
     *
     * @throws UnprocessableEntityHttpException
     */
    protected function beforeDestroy(Request $request, Model $entity)
    {
        $user = User::whereHas('roles', function ($query) use ($entity) {
            $query->where($entity->getKeyName(), $entity->getKey());
        })->first();

        if ($user) {
            throw new UnprocessableEntityHttpException(['role_id' => 'El rol no puede ser eliminado porque tiene usuarios asociados']);
        }

        return null;
    }
}
