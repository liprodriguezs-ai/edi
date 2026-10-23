<?php

namespace QCH\Core\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Role extends BaseModel
{
    use CentralConnection;
    use HasUuids;

    protected $keyType = 'string';

    protected $primaryKey = 'uuid';

    protected $table = 'roles';

    public $incrementing = false;

    public const SUPER_ADMIN = '61f7eafd-9f7e-436c-936b-adda37dd10e3';

    public const SLUG_SUPER_ADMIN = 'admin.super';

    public const ACCOUNT_OWNER = '47c151b4-1cf7-4e97-b7f6-5539b8b3f379';

    public const SLUG_ACCOUNT_OWNER = 'account.owner';

    // 2b532c6f-a2e0-4415-833d-46bb22f930dc,"RH",admin.rh,api

    public const RH = '2b532c6f-a2e0-4415-833d-46bb22f930dc';

    public const SLUG_RH = 'admin.rh';

    // fb02f264-d385-4279-a274-9461f4c83faa,"Colaborador",collaborator,api

    public const COLLABORATOR = 'fb02f264-d385-4279-a274-9461f4c83faa';

    public const SLUG_COLLABORATOR = 'collaborator';

    // c799c133-f930-4e97-97ce-28db37c354e7,"Afiliado",affiliate,api
    public const AFFILIATE = 'c799c133-f930-4e97-97ce-28db37c354e7';

    public const SLUG_AFFILIATE = 'affiliate';

    // fa5789eb-a28a-40d4-aeba-20fdd7b0a941,"Admin Empresa",company.admin,api
    public const COMPANY_ADMIN = 'fa5789eb-a28a-40d4-aeba-20fdd7b0a941';

    public const SLUG_COMPANY_ADMIN = 'company.admin';

    public const LAWYER = '019d4508-00c1-714c-a0ec-181671ef4fa6';

    public const SLUG_LAWYER = 'lawyer';

    // -------------------------------------------------------------------------
    // EDI residential complex roles (new — distinct UUIDs)
    // -------------------------------------------------------------------------

    public const ADMINISTRATOR = '1a2b3c4d-0001-0000-0000-000000000001';

    public const SLUG_ADMINISTRATOR = 'administrator';

    /**
     * Front desk ("Recepción", odd/tasks/edi-agenda.md S14): runs the agenda
     * (book, confirm, arrival, reschedule, cancel) and the child registry, with
     * no clinical powers -- it cannot start care (create.visit) nor screen.
     */
    public const ASSISTANT = '1a2b3c4d-000b-0000-0000-000000000011';

    public const SLUG_ASSISTANT = 'assistant';

    public const PRESIDENT = '1a2b3c4d-0002-0000-0000-000000000002';

    public const SLUG_PRESIDENT = 'president';

    public const TREASURER = '1a2b3c4d-0003-0000-0000-000000000003';

    public const SLUG_TREASURER = 'treasurer';

    public const SECRETARY = '1a2b3c4d-0004-0000-0000-000000000004';

    public const SLUG_SECRETARY = 'secretary';

    public const VOCAL = '1a2b3c4d-0005-0000-0000-000000000005';

    public const SLUG_VOCAL = 'vocal';

    public const OVERSIGHT_COMMITTEE = '1a2b3c4d-0006-0000-0000-000000000006';

    public const SLUG_OVERSIGHT_COMMITTEE = 'oversight_committee';

    public const SECURITY = '1a2b3c4d-0007-0000-0000-000000000007';

    public const SLUG_SECURITY = 'security';

    public const OPERATIONS_STAFF = '1a2b3c4d-0008-0000-0000-000000000008';

    public const SLUG_OPERATIONS_STAFF = 'operations_staff';

    public const RESIDENT = '1a2b3c4d-0009-0000-0000-000000000009';

    public const SLUG_RESIDENT = 'resident';

    public const RENTER = '1a2b3c4d-000a-0000-0000-000000000010';

    public const SLUG_RENTER = 'renter';

    /**
     * Operational roles of a organization: the ones an owner/administrator may hand out.
     * Deliberately EXCLUDES admin.super and account.owner (platform / account tier).
     *
     * @var list<string>
     */
    public const ORGANIZATION_SLUGS = [
        self::SLUG_ADMINISTRATOR,
        self::SLUG_ASSISTANT,
        self::SLUG_PRESIDENT,
        self::SLUG_TREASURER,
        self::SLUG_SECRETARY,
        self::SLUG_VOCAL,
        self::SLUG_OVERSIGHT_COMMITTEE,
        self::SLUG_SECURITY,
        self::SLUG_OPERATIONS_STAFF,
        self::SLUG_RESIDENT,
        self::SLUG_RENTER,
    ];

    /**
     * Residency-only roles: the floor everyone else may reference (e.g. when
     * registering a unit_user).
     *
     * @var list<string>
     */
    public const RESIDENCY_SLUGS = [
        self::SLUG_RESIDENT,
        self::SLUG_RENTER,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'name',
        'slug',
        'description',
        'system',
    ];

    /**
     * Role slugs (the `name` column — see database/seeders/csv/roles.csv, where
     * `name` holds the slug and `description` the Spanish label) that the given
     * actor is allowed to GRANT to another user.
     *
     * SECURITY BOUNDARY. The role to assign arrives from the request body
     * (`role` / `role_id`) on POST /api/user and PUT /api/user/{id}. Without
     * this check any holder of create.user / update.user — notably the
     * `administrator` role a organization hires — could mint a platform super admin
     * with cross-tenant access to every organization.
     *
     * Tiers (mirrors the catalogue AngularController::selects already exposes):
     *  - admin.super    → everything
     *  - account.owner  → organization roles + another account owner
     *  - administrator  → organization roles only (never owner, never super admin)
     *  - anyone else    → residency roles only
     *
     * @return list<string>
     */
    public static function assignableSlugsFor(?Authenticatable $actor): array
    {
        if (! $actor || ! method_exists($actor, 'hasRole')) {
            return self::RESIDENCY_SLUGS;
        }

        if ($actor->hasRole(self::SLUG_SUPER_ADMIN)) {
            return array_merge([self::SLUG_SUPER_ADMIN, self::SLUG_ACCOUNT_OWNER], self::ORGANIZATION_SLUGS);
        }

        if ($actor->hasRole(self::SLUG_ACCOUNT_OWNER)) {
            return array_merge([self::SLUG_ACCOUNT_OWNER], self::ORGANIZATION_SLUGS);
        }

        if ($actor->hasRole(self::SLUG_ADMINISTRATOR) || $actor->hasRole(self::SLUG_COMPANY_ADMIN)) {
            return self::ORGANIZATION_SLUGS;
        }

        return self::RESIDENCY_SLUGS;
    }

    /**
     * Whether the actor may grant the role identified by the given slug.
     */
    public static function canAssignSlug(?Authenticatable $actor, ?string $slug): bool
    {
        return $slug !== null && in_array($slug, self::assignableSlugsFor($actor), true);
    }
}
