<?php

namespace App\Http\Controllers;

use App\Models\MaritalStatus;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\Appointment\Models\AppointmentType;
use Modules\ChildRecord\Models\CaregiverRelationship;
use Modules\ChildRecord\Models\EducationLevel;
use Modules\ChildRecord\Models\Occupation;
use Modules\ChildRecord\Models\PopulationType;
use Modules\ChildRecord\Models\Sex;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Company\Models\Company;
use Modules\Country\Models\Country;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Models\ReferralSpecialty;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\ResultColor;
use Modules\Municipality\Models\Municipality;
use Modules\Screening\Models\ConfirmatoryTestResult;
use Modules\Screening\Models\ScreeningSituation;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Stimulation\Models\AttendancePaymentStatus;
use Modules\Stimulation\Models\BodyZone;
use Modules\Stimulation\Models\DidacticMaterial;
use Modules\Stimulation\Models\MassageMovement;
use Modules\Stimulation\Models\Song;
use Modules\Stimulation\Models\StimulationArea;
use Modules\Stimulation\Models\StimulationDynamic;
use Modules\Stimulation\Models\StimulationGroup;
use Modules\Stimulation\Models\StimulationGroupType;
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
        // ChildRecord / Clinic (edi-clinic-and-child-registry). No 'Child' entry
        // (D12): child search for visit creation goes through the child list
        // endpoint, not /selects (PII/volume smell).
        'Sex' => Sex::class,
        'PopulationType' => PopulationType::class,
        'CaregiverRelationship' => CaregiverRelationship::class,
        'EducationLevel' => EducationLevel::class,
        'Occupation' => Occupation::class,
        'HealthCenter' => HealthCenter::class,
        'PractitionerProfile' => PractitionerProfile::class,
        // Screening application (edi-screening-ui, REQ-SCR-013 delta). Pairs
        // 1:1 with CatalogType in frontend/src/shared/hooks/useCatalogs.ts —
        // never split across commits (Str::studly coupling).
        'ScreeningStatus' => ScreeningStatus::class,
        'ResultColor' => ResultColor::class,
        'ConfirmatoryTestResult' => ConfirmatoryTestResult::class,
        // Stimulation (edi-stimulation-sessions D12). Pairs 1:1 with CatalogType
        // in frontend/src/shared/hooks/useCatalogs.ts — never split across
        // commits (Str::studly coupling). No 'Child' entry (D12): child search
        // for session/enrollment pickers goes through the child list endpoint.
        'StimulationDynamic' => StimulationDynamic::class,
        'BodyZone' => BodyZone::class,
        'MassageMovement' => MassageMovement::class,
        'DidacticMaterial' => DidacticMaterial::class,
        'Song' => Song::class,
        'StimulationGroupType' => StimulationGroupType::class,
        'StimulationArea' => StimulationArea::class,
        'AttendancePaymentStatus' => AttendancePaymentStatus::class,
        'StimulationGroup' => StimulationGroup::class,
        // FollowUp (edi-followup-and-referral D13). Pairs 1:1 with CatalogType
        // in frontend/src/shared/hooks/useCatalogs.ts — never split across
        // commits (Str::studly coupling).
        'FollowUpType' => FollowUpType::class,
        'FollowUpStatus' => FollowUpStatus::class,
        'ReferralSpecialty' => ReferralSpecialty::class,
        // Agenda (odd/tasks/edi-agenda.md). Pairs 1:1 with CatalogType in
        // frontend/src/shared/hooks/useCatalogs.ts (Str::studly coupling).
        'AppointmentType' => AppointmentType::class,
        'AppointmentStatus' => AppointmentStatus::class,
        // Reports (edi-reports-and-indicators D12/REQ-RPT-012). HealthCenter
        // and ResultColor already existed above. Pairs 1:1 with CatalogType
        // in frontend/src/shared/hooks/useCatalogs.ts — never split across
        // commits (Str::studly coupling).
        'ScreeningSituation' => ScreeningSituation::class,
        'AgeGroup' => AgeGroup::class,
    ];

    protected $filterEspecial = [];

    /**
     * Relations each catalog may eager-load through `_with`.
     *
     * The parser rejects any relation missing from this list even when the model
     * declares it, and /selects answers a whole form in ONE batch, so a single
     * unlisted relation returned 500 and emptied EVERY dropdown on the screen --
     * 8 of them on the stimulation session form, which made the form unusable.
     * Keyed like $mapperSelects, by studly catalog name.
     *
     * @var array<string, array<int, string>>
     */
    protected $allowedRelations = [
        'PractitionerProfile' => ['user'],
    ];

    public function __construct()
    {
        // Roles operativos de la organización (asignables dentro del tenant).
        $organizationRoles = [
            Role::ADMINISTRATOR,
            Role::ASSISTANT,
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

                // El dueño de la cuenta y el administrador gestionan los roles de la organización.
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
            // One catalog must never take the batch down with it: a form asks for
            // all of its dropdowns at once, so an exception here used to answer 500
            // and leave EVERY dropdown on the screen empty. Degrade that catalog to
            // an empty list, report it, and keep serving the rest.
            try {
                $model = app($this->mapperSelects[$nameModel]);
                if (array_key_exists($nameModel, $this->filterEspecial)) {
                    $tableName = $model->getTable();
                    $builder = ($this->filterEspecial[$nameModel])($model, $params);
                } else {
                    $tableName = $model->getTable();
                    $builder = $model->query();
                }
                $builder = ApiHandler::parseMultiple(
                    $builder,
                    $this->allowedRelations[$nameModel] ?? [],
                    $params
                )->getBuilder();

                // check if active soft delete
                if ($nameModel != 'Role') {
                    $data[$type] = $builder->whereNull("{$tableName}.deleted_at")->get();
                } else {
                    $data[$type] = $builder->get();
                }
            } catch (\Throwable $e) {
                report($e);
                $data[$type] = [];
            }
        }

        return response()->json(['data' => $data]);
    }
}
