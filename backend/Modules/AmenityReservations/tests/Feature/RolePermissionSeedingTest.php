<?php

namespace Modules\AmenityReservations\Tests\Feature;

use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use QCH\Core\Models\Role as RoleCatalog;
use Tests\TestCase;

/**
 * S2 living contract for the two new permission entities (`amenity`,
 * `amenity_reservation`): asserts all 12 permissions exist after seeding and
 * are granted to the Kivo7 roles per the mapping decided in sdd-apply S2.
 * Mirrors the AuthorizationGuardTest "seeded role matrix" pattern, kept in a
 * dedicated file scoped to this slice so it stays independent of the shared
 * living-contract test (which already covers the other 15 modules).
 *
 * Role mapping (S2 decision — treated like Projects, a board-managed
 * common-area domain with an approval workflow):
 * - administrator / account_owner / company_admin: full CRUD (via the
 *   existing whereNotIn($permissionsOnlySuperAdmin) grant — nothing bespoke
 *   needed here since these permissions are NOT platform-exclusive).
 * - president / treasurer / secretary / vocal: read/create/update/report
 *   (writeNoDelete) — delete stays Admin/account_owner-only.
 * - oversight_committee: read-only on `amenity`, PLUS create.amenity_reservation
 *   (committee members can also be unit owners, so they may reserve for their
 *   own unit like a resident) — but no update/delete on amenity_reservation
 *   and no write at all on the amenity catalog.
 * - resident / renter: read.amenity + read.amenity_reservation +
 *   create.amenity_reservation only (residents book their OWN reservations).
 */
class RolePermissionSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITIES = ['amenity', 'amenity_reservation'];

    private const VERBS = ['read', 'create', 'update', 'delete', 'restore', 'report'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => ResetPermissionTableSeeder::class, '--force' => true]);
    }

    public function test_all_twelve_amenity_permissions_exist(): void
    {
        foreach (self::ENTITIES as $entity) {
            foreach (self::VERBS as $verb) {
                $this->assertDatabaseHas('permissions', ['name' => "{$verb}.{$entity}"]);
            }
        }
    }

    public function test_resident_holds_exactly_the_scoped_reservation_abilities(): void
    {
        $granted = $this->grantedPermissions(RoleCatalog::RESIDENT);

        $this->assertContains('read.amenity', $granted);
        $this->assertContains('read.amenity_reservation', $granted);
        $this->assertContains('create.amenity_reservation', $granted);

        $this->assertNotContains('create.amenity', $granted);
        $this->assertNotContains('update.amenity', $granted);
        $this->assertNotContains('delete.amenity', $granted);
        $this->assertNotContains('restore.amenity', $granted);
        $this->assertNotContains('report.amenity', $granted);
        $this->assertNotContains('update.amenity_reservation', $granted);
        $this->assertNotContains('delete.amenity_reservation', $granted);
        $this->assertNotContains('restore.amenity_reservation', $granted);
        $this->assertNotContains('report.amenity_reservation', $granted);
    }

    public function test_renter_holds_the_same_scoped_reservation_abilities_as_resident(): void
    {
        $granted = $this->grantedPermissions(RoleCatalog::RENTER);

        $this->assertContains('read.amenity', $granted);
        $this->assertContains('read.amenity_reservation', $granted);
        $this->assertContains('create.amenity_reservation', $granted);
        $this->assertNotContains('update.amenity_reservation', $granted);
        $this->assertNotContains('delete.amenity_reservation', $granted);
        $this->assertNotContains('delete.amenity', $granted);
    }

    public function test_administrator_and_account_owner_hold_full_management_abilities(): void
    {
        foreach ([RoleCatalog::ADMINISTRATOR, RoleCatalog::ACCOUNT_OWNER] as $roleId) {
            $granted = $this->grantedPermissions($roleId);

            foreach (self::ENTITIES as $entity) {
                foreach (self::VERBS as $verb) {
                    $this->assertContains("{$verb}.{$entity}", $granted, "Role {$roleId} must hold {$verb}.{$entity}");
                }
            }
        }
    }

    public function test_president_treasurer_secretary_and_vocal_manage_without_delete(): void
    {
        foreach ([RoleCatalog::PRESIDENT, RoleCatalog::TREASURER, RoleCatalog::SECRETARY, RoleCatalog::VOCAL] as $roleId) {
            $granted = $this->grantedPermissions($roleId);

            foreach (self::ENTITIES as $entity) {
                $this->assertContains("read.{$entity}", $granted, "Role {$roleId} must hold read.{$entity}");
                $this->assertContains("create.{$entity}", $granted, "Role {$roleId} must hold create.{$entity}");
                $this->assertContains("update.{$entity}", $granted, "Role {$roleId} must hold update.{$entity}");
                $this->assertContains("report.{$entity}", $granted, "Role {$roleId} must hold report.{$entity}");
                $this->assertNotContains("delete.{$entity}", $granted, "Role {$roleId} must NOT hold delete.{$entity} (Admin/account_owner only)");
                $this->assertNotContains("restore.{$entity}", $granted, "Role {$roleId} must NOT hold restore.{$entity} (Admin/account_owner only)");
            }
        }
    }

    /**
     * Oversight committee is read-only on the `amenity` catalog, but — since
     * board/committee members can also be unit owners — they hold the same
     * scoped `create.amenity_reservation` ability as a resident so they can
     * reserve for their own unit. They must NOT gain any other write ability
     * on either entity (no amenity management, no approving/rejecting/
     * cancelling other units' reservations).
     */
    public function test_oversight_committee_is_read_only_except_own_unit_reservation_create(): void
    {
        $granted = $this->grantedPermissions(RoleCatalog::OVERSIGHT_COMMITTEE);

        foreach (self::ENTITIES as $entity) {
            $this->assertContains("read.{$entity}", $granted);
            $this->assertNotContains("update.{$entity}", $granted);
            $this->assertNotContains("delete.{$entity}", $granted);
            $this->assertNotContains("restore.{$entity}", $granted);
        }

        $this->assertNotContains('create.amenity', $granted);
        $this->assertNotContains('report.amenity', $granted);

        $this->assertContains('create.amenity_reservation', $granted);
    }

    /**
     * @return array<string>
     */
    private function grantedPermissions(string $roleId): array
    {
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.uuid', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->pluck('permissions.name')
            ->all();
    }
}
