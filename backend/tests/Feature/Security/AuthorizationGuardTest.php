<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role as AppRole;
use App\Models\User;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Access\Models\AccessVerification;
use Modules\Access\Models\QrType;
use Modules\Access\Models\Visitor;
use Modules\Access\Models\VisitorMotive;
use Modules\Access\Models\VisitorStatus;
use Modules\Access\Services\AccessService;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatReaction;
use Modules\Chat\Services\ChatService;
use Modules\Collections\Models\AmountMode;
use Modules\Company\Models\Company;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Services\ProjectActivationService;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Models\Role as RoleCatalog;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Regression tests for the authorization & tenant-isolation security fixes.
 *
 * Authentication model: Sanctum token abilities gate every action. A token
 * created with ['*'] passes every tokenCan() check (board/admin); a token
 * created with a narrow ability list simulates a resident with limited scope.
 * The InitializeTenancyFromUser middleware resolves the tenant from the user's
 * user_tenant pivot, so each acting user is attached to the tenant under test
 * and the request carries X-Tenant-Id.
 */
final class AuthorizationGuardTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a central user attached to the given tenant (member via user_tenant).
     */
    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
            $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));
            $unit = Unit::create([
                'unit_type_id' => $unitType->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => $unitStatus->id,
            ]);
        });

        return $unit;
    }

    /**
     * Token with wildcard ability — simulates board/admin (passes every tokenCan()).
     */
    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    /**
     * Token with a narrow ability list — simulates a resident with limited scope.
     *
     * @param  array<string>  $abilities
     */
    private function residentToken(User $user, array $abilities = []): string
    {
        return $user->createToken('resident', $abilities)->plainTextToken;
    }

    /**
     * Build a tenant-scoped, token-authenticated request.
     *
     * Laravel's test client caches the resolved Sanctum guard user across requests
     * within a single test. When a test fires multiple requests with DIFFERENT
     * tokens (e.g. resident then admin), the second request would otherwise reuse
     * the first request's cached user. Flushing the resolved guards forces each
     * request to re-resolve its own bearer token — matching real per-request HTTP.
     */
    private function seedGlobalBoardRoles(): void
    {
        foreach (['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee'] as $slug) {
            AppRole::firstOrCreate(['name' => $slug, 'guard_name' => 'api']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Register a user as board member of THIS tenant's current board period —
     * chat board access is tenant-scoped (board_member of the current period),
     * not derived from the global Spatie role alone.
     */
    private function makeCurrentBoardMember(Tenant $tenant, User $user): void
    {
        $tenant->run(static function () use ($user): void {
            $period = BoardPeriod::create([
                'name' => 'Mesa 2026',
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            BoardMember::create([
                'board_period_id' => $period->id,
                'user_id' => $user->id,
                'board_role_id' => BoardRole::PRESIDENT,
            ]);
        });
    }

    // =========================================================================
    // C2 — Reports controller is board/admin gated (read.report)
    // =========================================================================

    public function test_c2_resident_cannot_read_reports_but_admin_can(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $admin = $this->makeUser($tenant);

        $residentToken = $this->residentToken($resident, ['read.unit']);
        foreach (['reports/delinquency', 'reports/account-balances', 'reports/audit-log'] as $uri) {
            $this->tenantRequest($residentToken, $tenant)
                ->getJson("/api/{$uri}")
                ->assertStatus(403);
        }

        $adminToken = $this->adminToken($admin);
        foreach (['reports/delinquency', 'reports/account-balances', 'reports/audit-log'] as $uri) {
            $this->tenantRequest($adminToken, $tenant)
                ->getJson("/api/{$uri}")
                ->assertStatus(200);
        }
    }

    public function test_c2_resident_with_read_report_ability_is_allowed(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $user = $this->makeUser($tenant);
        $token = $this->residentToken($user, ['read.report']);

        $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/delinquency')
            ->assertStatus(200);
    }

    // =========================================================================
    // C1 — audit-log report is tenant-isolated (audits carry tenant_id)
    // =========================================================================

    public function test_c1_audit_log_report_only_returns_current_tenant_rows(): void
    {
        $tenantA = $this->makeTenant('Audit A');
        $tenantB = $this->makeTenant('Audit B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        // Insert one audit row stamped for each tenant directly. (We assert the
        // report's tenant_id FILTER + the new schema column — the exact C1 fix.
        // We do not rely on the OwenIt write path because audit inserts are
        // deferred to afterCommit and would not be visible mid-test.)
        $auditableIdA = (string) Str::uuid();
        $auditableIdB = (string) Str::uuid();
        $this->insertAuditRow($tenantA->id, $auditableIdA);
        $this->insertAuditRow($tenantB->id, $auditableIdB);

        $adminA = $this->makeUser($tenantA);
        $token = $this->adminToken($adminA);

        $response = $this->tenantRequest($token, $tenantA)
            ->getJson('/api/reports/audit-log')
            ->assertStatus(200);

        $rows = $response->json('data') ?? [];

        $this->assertNotEmpty($rows, 'Tenant A should see its own audit rows');
        foreach ($rows as $row) {
            $this->assertSame(
                $tenantA->id,
                $row['tenant_id'] ?? null,
                'audit-log must NOT leak rows from another tenant'
            );
        }

        $auditableIds = array_column($rows, 'auditable_id');
        $this->assertNotContains($auditableIdB, $auditableIds, 'Tenant B audit row leaked into Tenant A report');
        $this->assertContains($auditableIdA, $auditableIds, 'Tenant A own audit row missing');
    }

    /**
     * Insert one audit row stamped with the given tenant id (mirrors what
     * QCH\Core\Models\Audit::save() does in production via the tenant scope).
     */
    private function insertAuditRow(string $tenantId, string $auditableId): void
    {
        DB::table('audits')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_type' => User::class,
            'user_id' => (string) Str::uuid(),
            'event' => 'created',
            'auditable_type' => Unit::class,
            'auditable_id' => $auditableId,
            'old_values' => json_encode([]),
            'new_values' => json_encode(['number' => '101']),
            'created_at' => now(),
        ]);
    }

    // =========================================================================
    // C3 — unit statement: resident ownership enforced; admin reads any
    // =========================================================================

    public function test_c3_resident_can_read_own_unit_statement_but_not_neighbor(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $ownUnit = $this->makeUnit($tenant);
        $neighborUnit = $this->makeUnit($tenant);

        $resident = $this->makeUser($tenant);

        $tenant->run(function () use ($ownUnit, $resident): void {
            $relationshipId = UnitRelationship::query()->value('id');
            UnitUser::create([
                'unit_id' => $ownUnit->id,
                'user_id' => $resident->id,
                'unit_relationship_id' => $relationshipId,
            ]);
        });

        $token = $this->residentToken($resident, ['read.unit']);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/unit/{$ownUnit->id}/statement")
            ->assertStatus(200);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/unit/{$neighborUnit->id}/statement")
            ->assertStatus(403);
    }

    public function test_c3_admin_can_read_any_unit_statement(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $someUnit = $this->makeUnit($tenant);
        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/unit/{$someUnit->id}/statement")
            ->assertStatus(200);
    }

    public function test_c3_resident_cannot_access_delinquency_dashboard(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $admin = $this->makeUser($tenant);

        $this->tenantRequest($this->residentToken($resident, ['read.unit']), $tenant)
            ->getJson('/api/delinquency')
            ->assertStatus(403);

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson('/api/delinquency')
            ->assertStatus(200);
    }

    // =========================================================================
    // H2 — Board self-grant is blocked (worst escalation)
    // =========================================================================

    public function test_h2_resident_cannot_start_board_period_and_no_role_granted(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $attacker = $this->makeUser($tenant);

        $presidentRoleId = null;
        $tenant->run(function () use (&$presidentRoleId): void {
            $presidentRoleId = BoardRole::PRESIDENT;
        });

        $token = $this->residentToken($attacker, ['read.unit']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Self-Grant Attempt',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'auto_close_current' => false,
                'members' => [
                    ['user_id' => $attacker->id, 'board_role_id' => $presidentRoleId, 'unit_id' => null],
                ],
            ])
            ->assertStatus(403);

        // CRITICAL: no Spatie role must have been granted to the attacker.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $fresh = User::withoutGlobalScopes()->find($attacker->id);
        $this->assertFalse(
            $fresh->hasRole('president'),
            'Blocked board start must NOT grant any global Spatie role to the attacker'
        );
    }

    public function test_h2_admin_can_start_board_period_and_role_is_granted(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $member = $this->makeUser($tenant);
        $treasurer = $this->makeUser($tenant);
        $secretary = $this->makeUser($tenant);

        $presidentRoleId = $treasurerRoleId = $secretaryRoleId = null;
        $tenant->run(function () use (&$presidentRoleId, &$treasurerRoleId, &$secretaryRoleId): void {
            $presidentRoleId = BoardRole::PRESIDENT;
            $treasurerRoleId = BoardRole::TREASURER;
            $secretaryRoleId = BoardRole::SECRETARY;
        });

        // A valid period must include the mandatory President/Treasurer/Secretary trio.
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Legit Period',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'auto_close_current' => false,
                'members' => [
                    ['user_id' => $member->id, 'board_role_id' => $presidentRoleId, 'unit_id' => null],
                    ['user_id' => $treasurer->id, 'board_role_id' => $treasurerRoleId, 'unit_id' => null],
                    ['user_id' => $secretary->id, 'board_role_id' => $secretaryRoleId, 'unit_id' => null],
                ],
            ])
            ->assertStatus(201);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $fresh = User::withoutGlobalScopes()->find($member->id);
        $this->assertTrue($fresh->hasRole('president'), 'Authorized board start must grant the Spatie role');
    }

    // =========================================================================
    // H2 — Announcements publish requires update.announcement
    // =========================================================================

    public function test_h2_resident_cannot_publish_announcement_but_admin_can(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $admin = $this->makeUser($tenant);

        $announcementId = null;
        $tenant->run(function () use (&$announcementId): void {
            $type = AnnouncementType::findOrFail(Catalog::id(AnnouncementType::class, 'Aviso general'));
            $priority = AnnouncementPriority::findOrFail(AnnouncementPriority::NORMAL);
            $draft = AnnouncementStatus::findOrFail(AnnouncementStatus::DRAFT);
            $announcementId = Announcement::create([
                'title' => 'Draft a publicar',
                'announcement_type_id' => $type->id,
                'announcement_priority_id' => $priority->id,
                'announcement_status_id' => $draft->id,
                'content' => 'Contenido.',
                'target_type' => 'all',
                'target_selector' => null,
                'channel_push' => false,
            ])->id;
        });

        $this->tenantRequest($this->residentToken($resident, ['read.announcement']), $tenant)
            ->postJson("/api/announcement/{$announcementId}/publish")
            ->assertStatus(403);

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/announcement/{$announcementId}/publish")
            ->assertStatus(200);
    }

    // =========================================================================
    // H2 — Chat hide requires moderator (update.chat_message)
    // =========================================================================

    public function test_h2_non_moderator_cannot_hide_chat_message(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant);
        $moderator = $this->makeUser($tenant);

        $messageId = null;
        $tenant->run(function () use ($author, &$messageId): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $service = app(ChatService::class);
            $service->joinChannel($channel, $author->id);
            $messageId = $service->postMessage($channel, $author->id, 'Mensaje a moderar')->id;
        });

        $this->tenantRequest($this->residentToken($author, ['read.chat_message']), $tenant)
            ->postJson("/api/chat-message/{$messageId}/hide")
            ->assertStatus(403);

        $this->tenantRequest($this->adminToken($moderator), $tenant)
            ->postJson("/api/chat-message/{$messageId}/hide")
            ->assertStatus(200);
    }

    // =========================================================================
    // H1 — access_log has no update/destroy route (immutable log)
    // =========================================================================

    public function test_h1_access_log_update_and_destroy_routes_are_not_registered(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);

        $accessLogId = (string) Str::uuid();

        // The resource is registered as only [index, show, store]. The GET path
        // /api/access_log/{id} exists (show), so PUT/DELETE on it return 405
        // (Method Not Allowed) — the update/destroy verbs are not registered.
        // The restore route does not exist at all → 404. Either way the log is
        // immutable through Orion: no update/destroy/restore handler runs.
        $this->tenantRequest($token, $tenant)
            ->putJson("/api/access_log/{$accessLogId}", ['visitor_name' => 'tampered'])
            ->assertStatus(405);

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/access_log/{$accessLogId}")
            ->assertStatus(405);

        // The restore custom route must also be absent.
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/access_log/{$accessLogId}/restore")
            ->assertStatus(404);
    }

    public function test_h1_checkout_and_correction_endpoints_still_work(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $admin = $this->makeUser($tenant);

        $logId = null;
        $tenant->run(function () use ($unit, &$logId): void {
            $verification = AccessVerification::query()->firstOrFail();
            $service = app(AccessService::class);
            $log = $service->manualEntry([
                'unit_id' => $unit->id,
                'visitor_name' => 'Repartidor',
                'access_verification_id' => $verification->id,
                'authorized_by' => 'Guardia',
            ]);
            $logId = $log->id;
        });

        $token = $this->adminToken($admin);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/access/{$logId}/checkout")
            ->assertStatus(200);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/access/{$logId}/correction", ['note' => 'Entró por puerta lateral'])
            ->assertStatus(200);
    }

    public function test_h1_resident_cannot_checkout_access_log(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);

        $logId = null;
        $tenant->run(function () use ($unit, &$logId): void {
            $verification = AccessVerification::query()->firstOrFail();
            $service = app(AccessService::class);
            $logId = $service->manualEntry([
                'unit_id' => $unit->id,
                'visitor_name' => 'Repartidor',
                'access_verification_id' => $verification->id,
            ])->id;
        });

        $this->tenantRequest($this->residentToken($resident, ['read.access_log']), $tenant)
            ->postJson("/api/access/{$logId}/checkout")
            ->assertStatus(403);
    }

    // =========================================================================
    // M2 — Chat: non-member cannot post (membership enforced in service)
    // =========================================================================

    public function test_m2_non_member_cannot_post_member_can(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant);
        $president->assignRole('president');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->makeCurrentBoardMember($tenant, $president);

        $tenant->run(function () use ($president): void {
            // Board channel: access is by target_type — only the board may enter.
            $channel = ChatChannel::where('name', 'Mesa directiva')->firstOrFail();
            $service = app(ChatService::class);

            $outsider = (string) Str::uuid(); // not board, not even a real user

            $threw = false;
            try {
                $service->postMessage($channel, $outsider, 'No debería entrar');
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'A user without channel access must not be able to post');

            // The board member matches the target and can post.
            $message = $service->postMessage($channel, $president->id, 'Tema de la mesa');
            $this->assertNotNull($message->id);
        });
    }

    public function test_m2_resident_cannot_post_in_read_only_channel_admin_can(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant);
        $president = $this->makeUser($tenant);
        $president->assignRole('president');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->makeCurrentBoardMember($tenant, $president);

        $tenant->run(function () use ($resident, $president): void {
            $channel = ChatChannel::where('name', 'Avisos oficiales')->firstOrFail();
            $this->assertSame('residents_read_only', $channel->mode);

            $service = app(ChatService::class);
            $service->joinChannel($channel, $resident->id);
            $service->joinChannel($channel, $president->id);

            $threw = false;
            try {
                $service->postMessage($channel, $resident->id, 'Aviso no autorizado');
            } catch (\InvalidArgumentException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'Residents must not post in a read-only channel');

            $message = $service->postMessage($channel, $president->id, 'Aviso oficial');
            $this->assertNotNull($message->id);
        });
    }

    // =========================================================================
    // ROUTING — literal GET routes must NOT be shadowed by the Orion show route
    // (Issue #2: announcement/board and visitor/mine were registered AFTER the
    //  Orion resource, so {id} matched "board"/"mine" as a UUID -> 500.)
    // =========================================================================

    public function test_announcement_board_route_is_not_shadowed_by_show(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);

        // Publish one announcement so the board has content.
        $tenant->run(function (): void {
            $type = AnnouncementType::findOrFail(Catalog::id(AnnouncementType::class, 'Aviso general'));
            $priority = AnnouncementPriority::findOrFail(AnnouncementPriority::NORMAL);
            $published = AnnouncementStatus::findOrFail(AnnouncementStatus::PUBLISHED);
            Announcement::create([
                'title' => 'En el tablón',
                'announcement_type_id' => $type->id,
                'announcement_priority_id' => $priority->id,
                'announcement_status_id' => $published->id,
                'content' => 'Contenido publicado.',
                'target_type' => 'all',
                'channel_push' => false,
                'publish_at' => now(),
            ]);
        });

        // "board" must hit the board() action (200), NOT the show route (UUID 500).
        $board = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson('/api/announcement/board')
            ->assertStatus(200)
            ->json('data');

        $this->assertNotEmpty($board, 'board endpoint returns published announcements');
        $this->assertContains('En el tablón', array_column($board, 'title'));
    }

    public function test_visitor_mine_route_is_not_shadowed_by_show(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);

        // Create a visitor owned by the resident.
        $tenant->run(function () use ($unit, $resident): void {
            $motive = VisitorMotive::query()->firstOrFail();
            $qrType = QrType::query()->firstOrFail();
            $status = VisitorStatus::query()->firstOrFail();
            Visitor::create([
                'name' => 'Visita Propia',
                'unit_id' => $unit->id,
                'visitor_motive_id' => $motive->id,
                'qr_type_id' => $qrType->id,
                'visitor_status_id' => $status->id,
                'created_by_user_id' => $resident->id,
                'qr_token' => (string) Str::uuid(),
                'arrives_by_vehicle' => false,
            ]);
        });

        // "mine" must hit mine() (200) and return the caller's visitors, NOT the
        // Orion show route parsing "mine" as a UUID (500).
        $response = $this->tenantRequest($this->residentToken($resident, ['read.visitor']), $tenant)
            ->getJson('/api/visitor/mine')
            ->assertStatus(200);

        $names = array_column($response->json('data') ?? $response->json(), 'name');
        $this->assertContains('Visita Propia', $names);
    }

    // =========================================================================
    // CONFIG — general config is tenant-isolated (Issue #3: the main-company
    // lookup leaked the first main company across all tenants).
    // =========================================================================

    public function test_config_general_is_tenant_isolated_between_two_tenants(): void
    {
        $tenantA = $this->makeTenant('Cluster Uno');
        $tenantB = $this->makeTenant('Cluster Dos');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $this->makeMainCompany($tenantA, 'Asociación Uno A.C.', 'RFCUNO0101AA1');
        $this->makeMainCompany($tenantB, 'Asociación Dos A.C.', 'RFCDOS0101BB2');

        $adminA = $this->makeUser($tenantA);
        $adminB = $this->makeUser($tenantB);

        $generalA = $this->tenantRequest($this->adminToken($adminA), $tenantA)
            ->getJson('/api/config/general')->assertStatus(200)->json('data');
        $this->assertSame('Asociación Uno A.C.', $generalA['legal_name']);
        $this->assertSame($tenantA->id, $generalA['tenant_id'], 'Tenant A sees only its own company');

        $generalB = $this->tenantRequest($this->adminToken($adminB), $tenantB)
            ->getJson('/api/config/general')->assertStatus(200)->json('data');
        $this->assertSame('Asociación Dos A.C.', $generalB['legal_name']);
        $this->assertSame($tenantB->id, $generalB['tenant_id'], 'Tenant B sees only its own company');

        $this->assertNotSame(
            $generalA['id'],
            $generalB['id'],
            'config/general must return a DIFFERENT main company per tenant (no cross-tenant leak)'
        );
    }

    /**
     * Create a tenant's main company (central model, tenant_id stamped explicitly,
     * mirroring CompanyService::ensureMainCompanyForTenant).
     */
    private function makeMainCompany(Tenant $tenant, string $legalName, string $rfc): void
    {
        $tenant->run(function () use ($tenant, $legalName, $rfc): void {
            Company::create([
                'slug' => 'main-'.Str::lower(Str::random(8)),
                'name' => $legalName,
                'legal_name' => $legalName,
                'rfc' => $rfc,
                'main' => true,
                'tenant_id' => $tenant->getTenantKey(),
            ]);
        });
    }

    // =========================================================================
    // CONFIG — collection settings persist over HTTP (Issue #4: dot-notation
    // keys were treated as nested by the validator -> nothing persisted).
    // =========================================================================

    public function test_collection_settings_persist_over_http(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $token = $this->adminToken($admin);

        $put = $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/collection-settings', [
                'collections.due_soon_days' => 10,
                'collections.physical_receipt' => true,
            ])
            ->assertStatus(200);

        // The PUT echoes back the persisted values (not an empty data:[]).
        // (data_get does not support escaped dots, so read the data array whole.)
        $putData = $put->json('data');
        $this->assertSame(10, $putData['collections.due_soon_days']);

        // Read-back returns what was written.
        $readBack = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/collection-settings')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(10, $readBack['collections.due_soon_days']);
        $this->assertTrue($readBack['collections.physical_receipt']);

        // The Setting row is actually persisted.
        $stored = null;
        $tenant->run(function () use (&$stored): void {
            $stored = Setting::getValue('collections.due_soon_days');
        });
        $this->assertSame(10, $stored, 'due_soon_days must be persisted to the Setting table');
    }

    // =========================================================================
    // GlobalObserver — a JSON-cast column written over an authenticated HTTP
    // request stores valid JSON, not a double-encoded string (Issue #5).
    // =========================================================================

    public function test_json_cast_target_selector_is_not_double_encoded_over_http(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);

        $typeId = $priorityId = $draftId = null;
        $tenant->run(function () use (&$typeId, &$priorityId, &$draftId): void {
            $typeId = Catalog::id(AnnouncementType::class, 'Aviso general');
            $priorityId = AnnouncementPriority::NORMAL;
            $draftId = AnnouncementStatus::DRAFT;
        });

        $selector = ['units' => ['A-101', 'A-102'], 'towers' => ['Torre Norte']];

        $created = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', [
                'title' => 'Aviso segmentado',
                'content' => 'Solo algunas unidades.',
                'announcement_type_id' => $typeId,
                'announcement_priority_id' => $priorityId,
                'announcement_status_id' => $draftId,
                // 'manual' (whitelisted scope): free-form target_type values are now
                // rejected with 422 by AnnouncementCreateRequest (no silent fallback).
                'target_type' => 'manual',
                'target_selector' => $selector,
                'channel_push' => false,
            ])
            ->assertStatus(201)
            ->json('data');

        // The decoded JSON must equal the original array (GlobalObserver::parseEmpty
        // skips cast columns, so the JSON is not re-encoded/double-escaped).
        $fresh = null;
        $tenant->run(function () use ($created, &$fresh): void {
            $fresh = Announcement::find($created['id']);
        });

        $this->assertIsArray($fresh->target_selector, 'target_selector must decode to an array, not a JSON string');
        $this->assertSame($selector, $fresh->target_selector);

        // The raw stored value is valid single-encoded JSON (not "\"{...}\"").
        $raw = $fresh->getRawOriginal('target_selector');
        $this->assertIsArray(json_decode($raw, true), 'stored target_selector must be valid (single-encoded) JSON');
    }

    // =========================================================================
    // FIX B1 — project-contribution revert-decline route exists & is authorized
    // =========================================================================

    public function test_b1_revert_decline_route_works_for_admin(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $admin = $this->makeUser($tenant);

        $contributionId = null;
        $tenant->run(function () use ($unit, &$contributionId): void {
            $voluntary = ParticipationType::findOrFail(ParticipationType::VOLUNTARY);
            $category = CommunityProjectCategory::findOrFail(Catalog::id(CommunityProjectCategory::class, 'Obra'));
            $fixedMode = AmountMode::findOrFail(AmountMode::FIXED_REQUIRED);
            $draft = CommunityProjectStatus::findOrFail(CommunityProjectStatus::DRAFT);

            $project = CommunityProject::create([
                'title' => 'Proyecto revert '.Str::random(5),
                'community_project_category_id' => $category->id,
                'participation_type_id' => $voluntary->id,
                'amount_mode_id' => $fixedMode->id,
                'fixed_amount' => 100000,
                'allow_decline' => true,
                'target_type' => 'single_unit',
                'target_selector' => ['unit_id' => $unit->id],
                'goal_amount' => 100000,
                'start_date' => '2026-04-01',
                'due_date' => '2026-08-31',
                'community_project_status_id' => $draft->id,
            ]);

            app(ProjectActivationService::class)->activate($project);
            $contributionId = ProjectContribution::where('community_project_id', $project->id)->value('id');
        });

        $token = $this->adminToken($admin);

        // Decline, then revert-decline via the route. Both must return 200.
        $this->tenantRequest($token, $tenant)
            ->postJson("/api/project-contribution/{$contributionId}/decline")
            ->assertStatus(200);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/project-contribution/{$contributionId}/revert-decline")
            ->assertStatus(200);

        $reverted = null;
        $tenant->run(function () use ($contributionId, &$reverted): void {
            $reverted = ProjectContribution::find($contributionId);
        });

        $this->assertFalse((bool) $reverted->declined, 'revert-decline must clear the declined flag');
    }

    // =========================================================================
    // FIX B2 — chat-message unreact route exists & removes the reaction
    // =========================================================================

    public function test_b2_unreact_route_removes_reaction(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $member = $this->makeUser($tenant);

        $messageId = null;
        $tenant->run(function () use ($member, &$messageId): void {
            $channel = ChatChannel::where('name', 'General')->firstOrFail();
            $service = app(ChatService::class);
            $service->joinChannel($channel, $member->id);
            $messageId = $service->postMessage($channel, $member->id, 'Mensaje a reaccionar')->id;
            $service->react(ChatMessage::find($messageId), $member->id, '👍');
        });

        $tenant->run(function () use ($messageId, $member): void {
            $this->assertSame(1, ChatReaction::where('chat_message_id', $messageId)->where('user_id', $member->id)->count());
        });

        $token = $this->adminToken($member);

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/chat-message/{$messageId}/react", ['emoji' => '👍'])
            ->assertStatus(200);

        $tenant->run(function () use ($messageId, $member): void {
            $this->assertSame(
                0,
                ChatReaction::where('chat_message_id', $messageId)->where('user_id', $member->id)->count(),
                'unreact route must remove the caller\'s reaction'
            );
        });
    }

    // =========================================================================
    // FIX B4 — raw Orion store on board_period is blocked; start guards enforced
    // =========================================================================

    public function test_b4_raw_board_period_store_is_blocked(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);

        $statusId = null;
        $tenant->run(function () use (&$statusId): void {
            $statusId = BoardPeriodStatus::CURRENT;
        });

        // Even an admin (create.board_period) cannot create a period via the raw Orion
        // store — it must go through the guarded /board_period/start flow → 422.
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_period', [
                'name' => 'Bypass Period',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'board_period_status_id' => $statusId,
            ])
            ->assertStatus(422);

        $this->assertSame(0, $this->inTenantCount($tenant), 'No board_period must be created via raw store');
    }

    public function test_b4_start_without_treasurer_returns_422(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);
        $president = $this->makeUser($tenant);
        $secretary = $this->makeUser($tenant);

        $presidentRoleId = $secretaryRoleId = null;
        $tenant->run(function () use (&$presidentRoleId, &$secretaryRoleId): void {
            $presidentRoleId = BoardRole::PRESIDENT;
            $secretaryRoleId = BoardRole::SECRETARY;
        });

        // Missing a Treasurer → 422 (spec §7.3.2 mandatory offices).
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Sin Tesorero',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'auto_close_current' => false,
                'members' => [
                    ['user_id' => $president->id, 'board_role_id' => $presidentRoleId, 'unit_id' => null],
                    ['user_id' => $secretary->id, 'board_role_id' => $secretaryRoleId, 'unit_id' => null],
                ],
            ])
            ->assertStatus(422);
    }

    /**
     * §7.5.1 — "Al iniciar nuevo periodo, se cierra automáticamente el anterior
     * si traslapa": without auto_close_current the overlap is rejected (422);
     * with auto_close_current=true the previous period is closed (end_date
     * truncated) and the new one starts (201).
     */
    public function test_b4_overlapping_period_dates_return_422(): void
    {
        $this->seedGlobalBoardRoles();

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant);

        [$p1, $t1, $s1, $p2, $t2, $s2] = [
            $this->makeUser($tenant), $this->makeUser($tenant), $this->makeUser($tenant),
            $this->makeUser($tenant), $this->makeUser($tenant), $this->makeUser($tenant),
        ];

        $presidentRoleId = $treasurerRoleId = $secretaryRoleId = null;
        $tenant->run(function () use (&$presidentRoleId, &$treasurerRoleId, &$secretaryRoleId): void {
            $presidentRoleId = BoardRole::PRESIDENT;
            $treasurerRoleId = BoardRole::TREASURER;
            $secretaryRoleId = BoardRole::SECRETARY;
        });

        $members = fn ($p, $t, $s) => [
            ['user_id' => $p->id, 'board_role_id' => $presidentRoleId, 'unit_id' => null],
            ['user_id' => $t->id, 'board_role_id' => $treasurerRoleId, 'unit_id' => null],
            ['user_id' => $s->id, 'board_role_id' => $secretaryRoleId, 'unit_id' => null],
        ];

        // First valid period → 201.
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Periodo 1',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'auto_close_current' => true,
                'members' => $members($p1, $t1, $s1),
            ])
            ->assertStatus(201);

        // Overlap WITHOUT auto_close_current → 422 (the current period blocks it).
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Periodo 2 traslapado',
                'start_date' => '2026-06-01',
                'end_date' => '2027-05-31',
                'auto_close_current' => false,
                'members' => $members($p2, $t2, $s2),
            ])
            ->assertStatus(422);

        // Overlap WITH auto_close_current → 201 and the previous period is
        // closed with its end_date truncated to the day before the new start.
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/board_period/start', [
                'name' => 'Periodo 2 traslapado',
                'start_date' => '2026-06-01',
                'end_date' => '2027-05-31',
                'auto_close_current' => true,
                'members' => $members($p2, $t2, $s2),
            ])
            ->assertStatus(201);

        $tenant->run(function (): void {
            $previous = BoardPeriod::where('name', 'Periodo 1')->firstOrFail();
            $this->assertSame(BoardPeriodStatus::CLOSED, $previous->board_period_status_id);
            $this->assertSame('2026-05-31', $previous->end_date->toDateString());

            $current = BoardPeriod::where('board_period_status_id', BoardPeriodStatus::CURRENT)->get();
            $this->assertCount(1, $current, 'Only the new period remains current');
            $this->assertSame('Periodo 2 traslapado', $current->first()->name);
        });
    }

    private function inTenantCount(Tenant $tenant): int
    {
        $count = 0;
        $tenant->run(function () use (&$count): void {
            $count = BoardPeriod::count();
        });

        return $count;
    }

    // =========================================================================
    // SEEDED ROLE MATRIX — living contract of the spec permission tables
    // (§3.2 'Matriz simplificada' plus each module's 'Actores y permisos').
    // Asserted against role_has_permissions directly so the contract holds even
    // for abilities whose endpoint gating lands in a later wave
    // (cancel.charge, void.expense, manage.subscription).
    // =========================================================================

    public function test_seeded_role_matrix_codifies_the_spec_permission_tables(): void
    {
        Artisan::call('db:seed', ['--class' => ResetPermissionTableSeeder::class, '--force' => true]);

        // §8.2 — 'Cancelar cargo' / 'Aplicar descuento': Tes ❌, Pres ✅, Adm ✅.
        $this->assertRoleLacks('treasurer', RoleCatalog::TREASURER, ['cancel.charge', 'discount.charge'], '§8.2');
        $this->assertRoleHas('president', RoleCatalog::PRESIDENT, ['cancel.charge', 'discount.charge', 'update.charge'], '§8.2');
        $this->assertRoleHas('administrator', RoleCatalog::ADMINISTRATOR, ['cancel.charge', 'discount.charge'], '§8.2');

        // §9.2 — bank accounts are Admin-only; void.expense is Pres/Adm; the
        // treasurer registers/edits expenses but never voids or deletes them.
        $this->assertRoleHas('treasurer', RoleCatalog::TREASURER, ['read.bank_account', 'create.expense', 'update.expense'], '§9.2');
        $this->assertRoleLacks('treasurer', RoleCatalog::TREASURER, ['create.bank_account', 'update.bank_account', 'delete.bank_account', 'void.expense', 'delete.expense'], '§9.2');
        $this->assertRoleHas('president', RoleCatalog::PRESIDENT, ['create.expense', 'update.expense', 'void.expense', 'read.bank_account'], '§9.2');
        $this->assertRoleLacks('president', RoleCatalog::PRESIDENT, ['create.bank_account', 'delete.expense'], '§9.2');
        $this->assertRoleHas('administrator', RoleCatalog::ADMINISTRATOR, ['create.bank_account', 'void.expense'], '§9.2');

        // §10.2 — 'Eliminar proveedor' is Admin-only; president creates/edits.
        $this->assertRoleHas('treasurer', RoleCatalog::TREASURER, ['create.provider', 'update.provider'], '§10.2');
        $this->assertRoleLacks('treasurer', RoleCatalog::TREASURER, ['delete.provider', 'restore.provider'], '§10.2');
        $this->assertRoleHas('president', RoleCatalog::PRESIDENT, ['create.provider', 'update.provider'], '§10.2');
        $this->assertRoleLacks('president', RoleCatalog::PRESIDENT, ['delete.provider'], '§10.2');

        // §11.2 — 'Crear proyecto: Tes ✅', 'Marcar como ejecutado: Tes ✅'.
        $this->assertRoleHas('treasurer', RoleCatalog::TREASURER, ['create.community_project', 'update.community_project'], '§11.2');

        // §6.2 / §6.6 — president edits units (create stays Admin-only); the
        // treasurer manages person↔unit associations for the renter-change flow.
        $this->assertRoleHas('president', RoleCatalog::PRESIDENT, ['update.unit'], '§6.2');
        $this->assertRoleLacks('president', RoleCatalog::PRESIDENT, ['create.unit', 'delete.unit'], '§6.2');
        $this->assertRoleLacks('treasurer', RoleCatalog::TREASURER, ['update.unit'], '§6.2');
        $this->assertRoleHas('treasurer', RoleCatalog::TREASURER, ['create.unit_user', 'update.unit_user'], '§6.6');

        // §7.2 — the board directory is public; staff management is Admin-only
        // (president edits during his period — the window check is in code).
        foreach ([['resident', RoleCatalog::RESIDENT], ['renter', RoleCatalog::RENTER], ['treasurer', RoleCatalog::TREASURER]] as [$label, $roleId]) {
            $this->assertRoleHas($label, $roleId, ['read.board_period', 'read.board_member'], '§7.2');
        }
        $this->assertRoleHas('treasurer', RoleCatalog::TREASURER, ['read.collaborator'], '§7.2');
        $this->assertRoleLacks('treasurer', RoleCatalog::TREASURER, ['create.collaborator', 'update.collaborator', 'delete.collaborator'], '§7.2');
        $this->assertRoleHas('president', RoleCatalog::PRESIDENT, ['read.collaborator', 'update.collaborator'], '§7.2');
        $this->assertRoleLacks('president', RoleCatalog::PRESIDENT, ['create.collaborator', 'delete.collaborator'], '§7.2');

        // §12.2 — treasurer and vocal author announcements (own-only in code).
        $this->assertRoleHas('treasurer', RoleCatalog::TREASURER, ['create.announcement', 'update.announcement', 'delete.announcement'], '§12.2');
        $this->assertRoleHas('vocal', RoleCatalog::VOCAL, ['create.announcement', 'update.announcement', 'delete.announcement'], '§12.2');
        $this->assertRoleLacks('resident', RoleCatalog::RESIDENT, ['create.announcement'], '§12.2');

        // §13.4.3 — custom channels: only admin/president/secretary create them.
        foreach ([['administrator', RoleCatalog::ADMINISTRATOR], ['president', RoleCatalog::PRESIDENT], ['secretary', RoleCatalog::SECRETARY]] as [$label, $roleId]) {
            $this->assertRoleHas($label, $roleId, ['create.chat_channel'], '§13.4.3');
        }
        foreach ([['treasurer', RoleCatalog::TREASURER], ['vocal', RoleCatalog::VOCAL], ['oversight_committee', RoleCatalog::OVERSIGHT_COMMITTEE], ['security', RoleCatalog::SECURITY], ['operations_staff', RoleCatalog::OPERATIONS_STAFF]] as [$label, $roleId]) {
            $this->assertRoleLacks($label, $roleId, ['create.chat_channel', 'update.chat_channel', 'delete.chat_channel'], '§13.4.3');
        }

        // §14.2 — QR + full log for the treasurer; blacklist only Pres/Adm; the
        // guard reads the list but cannot block.
        $this->assertRoleHas('treasurer', RoleCatalog::TREASURER, ['create.visitor', 'read.access_log'], '§14.2');
        $this->assertRoleLacks('treasurer', RoleCatalog::TREASURER, ['create.blacklist_entry'], '§14.2');
        $this->assertRoleHas('president', RoleCatalog::PRESIDENT, ['create.visitor', 'create.blacklist_entry', 'update.blacklist_entry'], '§14.2');
        $this->assertRoleHas('security', RoleCatalog::SECURITY, ['read.blacklist_entry'], '§14.2');
        $this->assertRoleLacks('security', RoleCatalog::SECURITY, ['create.blacklist_entry', 'update.blacklist_entry'], '§14.2');

        // §15.2 — audit log: CdeV/Pres yes, Tes no; scheduling: Tes/Pres yes, CdeV no.
        $this->assertRoleLacks('treasurer', RoleCatalog::TREASURER, ['read.audit'], '§15.2');
        $this->assertRoleHas('president', RoleCatalog::PRESIDENT, ['read.audit', 'create.scheduled_report'], '§15.2');
        $this->assertRoleHas('oversight_committee', RoleCatalog::OVERSIGHT_COMMITTEE, ['read.audit', 'read.report'], '§15.2');
        $this->assertRoleLacks('oversight_committee', RoleCatalog::OVERSIGHT_COMMITTEE, ['create.scheduled_report'], '§15.2');
        $this->assertRoleHas('treasurer', RoleCatalog::TREASURER, ['create.scheduled_report'], '§15.2');

        // §16.2 — 'Gestionar suscripción: Adm ✅' without granting platform-wide
        // tenant administration.
        $this->assertRoleHas('administrator', RoleCatalog::ADMINISTRATOR, ['manage.subscription'], '§16.2');
        $this->assertRoleHas('account_owner', RoleCatalog::ACCOUNT_OWNER, ['manage.subscription'], '§16.2');
        $this->assertRoleLacks('administrator', RoleCatalog::ADMINISTRATOR, ['update.tenant'], '§16.2');
        $this->assertRoleLacks('president', RoleCatalog::PRESIDENT, ['manage.subscription'], '§16.2');
        $this->assertRoleLacks('treasurer', RoleCatalog::TREASURER, ['manage.subscription'], '§16.2');
        $this->assertRoleLacks('company_admin', RoleCatalog::COMPANY_ADMIN, ['manage.subscription'], '§16.2');

        // §18.3.2 — residents/renters read profiles, never the blanket update verb.
        foreach ([['resident', RoleCatalog::RESIDENT], ['renter', RoleCatalog::RENTER]] as [$label, $roleId]) {
            $this->assertRoleHas($label, $roleId, ['read.user_profile'], '§18.3.2');
            $this->assertRoleLacks($label, $roleId, ['update.user_profile', 'delete.user_profile'], '§18.3.2');
        }
    }

    public function test_seeded_central_catalog_writes_are_super_admin_exclusive(): void
    {
        Artisan::call('db:seed', ['--class' => ResetPermissionTableSeeder::class, '--force' => true]);

        // Catálogos CENTRALES (FSM/selectores de comportamiento) compartidos por
        // TODOS los tenants: una escritura de un admin de clúster mutaría a todos
        // a la vez → exclusivos de plataforma. Spot-check por familia de módulo.
        $centralWrites = [
            'update.charge_status', 'update.expense_status', 'delete.visitor_status',
            'update.announcement_status', 'delete.chat_channel_type', 'update.board_role',
            'delete.payment_method', 'update.beneficiary_type', 'delete.community_project_status',
        ];

        // Catálogos AUTOADMINISTRABLES (tenant-scoped, TenantScope acota filas):
        // el admin del clúster SÍ los gestiona.
        $tenantCatalogWrites = ['delete.unit_status', 'create.provider_type', 'update.employee_shift'];

        foreach ([['account_owner', RoleCatalog::ACCOUNT_OWNER], ['company_admin', RoleCatalog::COMPANY_ADMIN], ['administrator', RoleCatalog::ADMINISTRATOR]] as [$label, $roleId]) {
            $this->assertRoleLacks($label, $roleId, $centralWrites, 'central catalogs');
            $this->assertRoleHas($label, $roleId, ['read.charge_status', 'read.unit_status'], 'central catalogs');
            $this->assertRoleHas($label, $roleId, $tenantCatalogWrites, 'catálogos autoadministrables');
        }

        $this->assertRoleHas('super_admin', RoleCatalog::SUPER_ADMIN, ['update.charge_status', 'delete.unit_status', 'update.expense_status'], 'central catalogs');

        // §18.2.1 — the audit log is append-only for tenant roles.
        $this->assertRoleHas('account_owner', RoleCatalog::ACCOUNT_OWNER, ['read.audit'], '§18.2.1');
        $this->assertRoleLacks('account_owner', RoleCatalog::ACCOUNT_OWNER, ['create.audit', 'update.audit', 'delete.audit', 'restore.audit'], '§18.2.1');
        $this->assertRoleLacks('administrator', RoleCatalog::ADMINISTRATOR, ['create.audit', 'update.audit', 'delete.audit', 'restore.audit'], '§18.2.1');
    }

    /**
     * @param  array<string>  $permissions
     */
    private function assertRoleHas(string $label, string $roleId, array $permissions, string $specRef): void
    {
        $granted = $this->grantedPermissions($roleId);
        foreach ($permissions as $permission) {
            $this->assertContains($permission, $granted, "{$specRef}: '{$label}' must HAVE '{$permission}'");
        }
    }

    /**
     * @param  array<string>  $permissions
     */
    private function assertRoleLacks(string $label, string $roleId, array $permissions, string $specRef): void
    {
        $granted = $this->grantedPermissions($roleId);
        foreach ($permissions as $permission) {
            $this->assertNotContains($permission, $granted, "{$specRef}: '{$label}' must NOT have '{$permission}'");
        }
    }

    /**
     * @var array<string, array<string>>
     */
    private array $grantedPermissionsCache = [];

    /**
     * @return array<string>
     */
    private function grantedPermissions(string $roleId): array
    {
        return $this->grantedPermissionsCache[$roleId] ??= DB::table('role_has_permissions')
            ->join('permissions', 'permissions.uuid', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->pluck('permissions.name')
            ->all();
    }
}
