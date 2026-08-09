<?php

namespace App\Http\Controllers;

use App\Models\MaritalStatus;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Company\Models\Company;
use Modules\Country\Models\Country;
use Modules\Municipality\Models\Municipality;
use Modules\Tenant\Models\TenantPaymentType;
use Modules\User\Models\UserRegisterStatus;
use QCH\Core\Http\Api\ApiHandler;
use QCH\Core\Http\Controllers\Helpers;
use QCH\Core\Models\Role;

class AngularController extends Controller
{
    use Helpers;

    protected $mapperSelects = [
        'Role' => Role::class,
        'Country' => Country::class,
        'Municipality' => Municipality::class,
        'MaritalStatus' => MaritalStatus::class,
        'UserRegisterStatus' => UserRegisterStatus::class,
        'UserPromoter' => User::class,
        'User' => User::class,
        'Company' => Company::class,
        'Permission' => Permission::class,
        'TenantPaymentType' => TenantPaymentType::class,
    ];

    protected $filterEspecial = [];

    public function __construct()
    {
        // Roles operativos del organización (asignables dentro del tenant).
        $organizationRoles = [
            Role::ADMINISTRATOR,
            Role::PRESIDENT,
            Role::TREASURER,
            Role::SECRETARY,
            Role::VOCAL,
            Role::OVERSIGHT_COMMITTEE,
            Role::SECURITY,
            Role::OPERATIONS_STAFF,
            Role::RESIDENT,
            Role::RENTER,
        ];

        $this->filterEspecial = [
            'Role' => function ($model, $params) use ($organizationRoles) {
                $user = auth()->user();

                if ($user->hasRole(Role::SLUG_SUPER_ADMIN)) {
                    return $model->whereIn('uuid', array_merge([Role::SUPER_ADMIN, Role::ACCOUNT_OWNER], $organizationRoles));
                }

                // El dueño de la cuenta y el administrador gestionan los roles del organización.
                if ($user->hasRole(Role::SLUG_ACCOUNT_OWNER) || $user->hasRole(Role::SLUG_ADMINISTRATOR)) {
                    return $model->whereIn('uuid', $organizationRoles);
                }

                // El resto solo puede referenciar roles de residencia (p.ej. alta de unit_user).
                return $model->whereIn('uuid', [Role::RESIDENT, Role::RENTER]);
            },
        ];
    }

    public function selects(Request $request)
    {
        $selects = $request->all();
        $data = [];
        foreach ($selects as $type => $params) {
            $nameModel = Str::studly($type);
            if (! array_key_exists($nameModel, $this->mapperSelects)) {
                continue;
            }
            $model = app($this->mapperSelects[$nameModel]);
            if (array_key_exists($nameModel, $this->filterEspecial)) {
                $tableName = $model->getTable();
                $builder = ($this->filterEspecial[$nameModel])($model, $params);
            } else {
                $tableName = $model->getTable();
                $builder = $model->query();
            }
            $builder = ApiHandler::parseMultiple($builder, [], $params)->getBuilder();

            // check if active soft delete
            if ($nameModel != 'Role') {
                $data[$type] = $builder->whereNull("{$tableName}.deleted_at")->get();
            } else {
                $data[$type] = $builder->get();
            }
        }

        return response()->json(['data' => $data]);
    }
}
