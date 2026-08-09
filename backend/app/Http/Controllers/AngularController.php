<?php

namespace App\Http\Controllers;

use App\Models\MaritalStatus;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Access\Models\AccessEntryType;
use Modules\Access\Models\AccessVerification;
use Modules\Access\Models\BlacklistType;
use Modules\Access\Models\QrType;
use Modules\Access\Models\VisitorMotive;
use Modules\Access\Models\VisitorStatus;
use Modules\AmenityReservations\Models\ReservationMode;
use Modules\AmenityReservations\Models\ReservationStatus;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Chat\Models\ChatChannelType;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collaborator\Models\EmployeePosition;
use Modules\Collaborator\Models\EmployeeShift;
use Modules\Collaborator\Models\PayFrequency;
use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargeBatchStatus;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Models\Periodicity;
use Modules\Collections\Models\SurchargeMode;
use Modules\Company\Models\Company;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Complaint\Models\ComplaintStatus as ComplaintStatusModel;
use Modules\Country\Models\Country;
use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitFeature;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Municipality\Models\Municipality;
use Modules\Package\Models\PackageCarrier;
use Modules\Package\Models\PackageStatus;
use Modules\Package\Models\PackageType;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContributionStatus;
use Modules\Providers\Models\Provider;
use Modules\Providers\Models\ProviderServiceType;
use Modules\Providers\Models\ProviderType;
use Modules\Tenant\Models\TenantPaymentType;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\Treasury\Models\BeneficiaryType;
use Modules\Treasury\Models\ExpenseCategory;
use Modules\Treasury\Models\ExpenseStatus;
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
        // Housing
        'Tower' => Tower::class,
        'Section' => Section::class,
        'Unit' => Unit::class,
        'UnitType' => UnitType::class,
        'UnitStatus' => UnitStatus::class,
        'UnitRelationship' => UnitRelationship::class,
        'UnitFeature' => UnitFeature::class,
        // Board & Staff
        'BoardRole' => BoardRole::class,
        'BoardPeriodStatus' => BoardPeriodStatus::class,
        'Collaborator' => Collaborator::class,
        'EmployeePosition' => EmployeePosition::class,
        'PayFrequency' => PayFrequency::class,
        'EmployeeShift' => EmployeeShift::class,
        // Collections
        'ChargeConcept' => ChargeConcept::class,
        'ChargePolicy' => ChargePolicy::class,
        'ChargeStatus' => ChargeStatus::class,
        'PaymentStatus' => PaymentStatus::class,
        'ChargeBatchStatus' => ChargeBatchStatus::class,
        'ChargeConceptCategory' => ChargeConceptCategory::class,
        'AmountMode' => AmountMode::class,
        'Periodicity' => Periodicity::class,
        'PaymentMethod' => PaymentMethod::class,
        'SurchargeMode' => SurchargeMode::class,
        // Treasury
        'BankAccount' => BankAccount::class,
        'BankAccountType' => BankAccountType::class,
        'BankAccountPurpose' => BankAccountPurpose::class,
        'ExpenseCategory' => ExpenseCategory::class,
        'ExpenseStatus' => ExpenseStatus::class,
        'BeneficiaryType' => BeneficiaryType::class,
        // Providers
        'Provider' => Provider::class,
        'ProviderType' => ProviderType::class,
        'ProviderServiceType' => ProviderServiceType::class,
        // Community Projects
        'CommunityProjectStatus' => CommunityProjectStatus::class,
        'CommunityProjectCategory' => CommunityProjectCategory::class,
        'ParticipationType' => ParticipationType::class,
        'ProjectContributionStatus' => ProjectContributionStatus::class,
        // Announcements
        'AnnouncementType' => AnnouncementType::class,
        'AnnouncementPriority' => AnnouncementPriority::class,
        'AnnouncementStatus' => AnnouncementStatus::class,
        // Chat
        'ChatChannelType' => ChatChannelType::class,
        // Access
        'VisitorMotive' => VisitorMotive::class,
        'VisitorStatus' => VisitorStatus::class,
        'QrType' => QrType::class,
        'AccessEntryType' => AccessEntryType::class,
        'AccessVerification' => AccessVerification::class,
        'BlacklistType' => BlacklistType::class,
        // Package
        'PackageCarrier' => PackageCarrier::class,
        'PackageType' => PackageType::class,
        'PackageStatus' => PackageStatus::class,
        // Amenity Reservations
        'ReservationMode' => ReservationMode::class,
        'ReservationStatus' => ReservationStatus::class,
        // Incidencias
        'ComplaintCategory' => ComplaintCategory::class,
        'ComplaintPriority' => ComplaintPriority::class,
        'ComplaintStatus' => ComplaintStatusModel::class,
    ];

    protected $filterEspecial = [];

    public function __construct()
    {
        // Roles operativos del clúster (asignables dentro del tenant).
        $clusterRoles = [
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
            'Role' => function ($model, $params) use ($clusterRoles) {
                $user = auth()->user();

                if ($user->hasRole(Role::SLUG_SUPER_ADMIN)) {
                    return $model->whereIn('uuid', array_merge([Role::SUPER_ADMIN, Role::ACCOUNT_OWNER], $clusterRoles));
                }

                // El dueño de la cuenta y el administrador gestionan los roles del clúster.
                if ($user->hasRole(Role::SLUG_ACCOUNT_OWNER) || $user->hasRole(Role::SLUG_ADMINISTRATOR)) {
                    return $model->whereIn('uuid', $clusterRoles);
                }

                // El resto solo puede referenciar roles de residencia (p.ej. alta de unit_user).
                return $model->whereIn('uuid', [Role::RESIDENT, Role::RENTER]);
            },

            // OTHER es un concepto técnico de respaldo (el texto libre "Otro" del cobro
            // directo se resuelve en el backend); NUNCA debe ofrecerse como opción en un
            // dropdown. Se excluye del catálogo /selects — `code` es único y NOT NULL, así
            // que solo oculta esa fila del sistema, jamás un concepto legítimo del admin.
            'ChargeConcept' => function ($model, $params) {
                return $model->where('code', '!=', ChargeConcept::CODE_OTHER);
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
