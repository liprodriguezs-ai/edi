<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Models\Role as AppRole;
use App\Models\User;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementRead;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Board\Models\BoardRole;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * HTTP + delivery tests for Module 8 — Announcements (findings batch).
 *
 * Covers:
 *  - Publish fans out to the REAL resolved audience (recipients, not no-ops) and
 *    users outside the targeted tower neither receive nor see the announcement.
 *  - Priority → channel matrix (§12.6): normal=log, important=+email, urgent=+sms
 *    with data.urgent + category announcements.
 *  - Scheduled publication: future publish_at stays draft and silent; the module
 *    cron activates and notifies exactly ONCE; expired published rows are archived.
 *  - Ownership (§12.2): update/delete only by the author; President bypasses.
 *  - Store forces DRAFT status + server-side author (no client spoofing).
 *  - Unknown target_type → 422 (no silent "all" fallback).
 *  - 'board' audience: only current board members are notified / see the board.
 *  - Edited published announcement stamps edited_at and notifies prior readers.
 *  - Read metrics endpoint + is_read flag on the board.
 *  - 6th attachment rejected with 422 (no silent rotation).
 *  - Catalog search no longer references the nonexistent 'key' column.
 */
final class AnnouncementsHttpTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    private SpyNotificationDispatcher $dispatcherSpy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.channels' => ['log']]);

        $this->dispatcherSpy = new SpyNotificationDispatcher;
        $this->app->instance(NotificationDispatcher::class, $this->dispatcherSpy);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeUser(Tenant $tenant, string $name = 'User'): User
    {
        $user = User::create([
            'name' => $name.' '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    /**
     * Unit in a tower + active unit_user pivot for the given user.
     */
    private function makeResidentInTower(Tenant $tenant, User $user, Tower $tower): Unit
    {
        $unit = null;
        $tenant->run(function () use ($user, $tower, &$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'number' => (string) mt_rand(1, 99999),
                'tower_id' => $tower->id,
                'floor' => '1',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'start_date' => now()->toDateString(),
            ]);
        });

        return $unit;
    }

    private function makeTower(Tenant $tenant, string $name): Tower
    {
        $tower = null;
        $tenant->run(function () use ($name, &$tower): void {
            $tower = Tower::create(['name' => $name, 'floors_count' => 3]);
        });

        return $tower;
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    /**
     * Token simulating a board-member creator (secretary/treasurer/vocal style).
     *
     * @param  array<string>  $abilities
     */
    private function creatorToken(User $user, array $abilities = [
        'read.announcement',
        'create.announcement',
        'update.announcement',
        'delete.announcement',
    ]): string
    {
        return $user->createToken('creator', $abilities)->plainTextToken;
    }

    private function residentToken(User $user): string
    {
        return $user->createToken('resident', ['read.announcement'])->plainTextToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function announcementPayload(Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Aviso '.Str::random(6),
            'content' => 'Contenido del aviso de prueba.',
            'announcement_type_id' => Catalog::id(AnnouncementType::class, 'Aviso general'),
            'announcement_priority_id' => AnnouncementPriority::NORMAL,
            'target_type' => 'all',
        ], $overrides);
    }

    private function fakePdfFile(string $name = 'adjunto.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_ann_test_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 announcement attachment ', 50));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    // =========================================================================
    // A. Publish → real recipients per audience; outsiders excluded (board too)
    // =========================================================================

    public function test_publish_by_tower_notifies_only_tower_residents_and_board_hides_from_outsiders(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $insider = $this->makeUser($tenant, 'Torre A');
        $outsider = $this->makeUser($tenant, 'Torre B');

        $towerA = $this->makeTower($tenant, 'Torre A');
        $towerB = $this->makeTower($tenant, 'Torre B');
        $this->makeResidentInTower($tenant, $insider, $towerA);
        $this->makeResidentInTower($tenant, $outsider, $towerB);

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$towerA->id]],
            ]))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/publish")
            ->assertStatus(200);

        // Dispatcher received the REAL resolved audience (announcements-01).
        $publishCall = $this->dispatcherSpy->lastCall();
        $this->assertNotNull($publishCall, 'publish must route through the dispatcher');
        $this->assertContains($insider->id, $publishCall['recipients'], 'Tower A resident must be a recipient');
        $this->assertNotContains($outsider->id, $publishCall['recipients'], 'Tower B resident must NOT be a recipient');
        $this->assertSame('announcements', $publishCall['data']['category'] ?? null, 'category must be announcements');

        // In-app rows only for the audience.
        $tenant->run(function () use ($insider, $outsider): void {
            $this->assertSame(1, NotificationUser::where('user_id', $insider->id)->count());
            $this->assertSame(0, NotificationUser::where('user_id', $outsider->id)->count());
        });

        // Board respects the audience (announcements-05).
        $insiderBoard = $this->tenantRequest($this->residentToken($insider), $tenant)
            ->getJson('/api/announcement/board')
            ->assertStatus(200)
            ->json('data');
        $this->assertContains($ann['id'], array_column($insiderBoard, 'id'), 'Insider must see the announcement');

        $outsiderBoard = $this->tenantRequest($this->residentToken($outsider), $tenant)
            ->getJson('/api/announcement/board')
            ->assertStatus(200)
            ->json('data');
        $this->assertNotContains($ann['id'], array_column($outsiderBoard, 'id'), 'Outsider must NOT see the announcement');
    }

    /**
     * Fase 3 — a large 'all' audience fans out through the BATCHED in-app path
     * (recordInApp's single bulk insert) and reaches EVERY active resident.
     * Announcements inherit the batching from notifyUsers without any change.
     */
    public function test_publish_to_all_audience_records_in_app_for_every_resident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $tower = $this->makeTower($tenant, 'Torre Única');

        $residents = [];
        for ($i = 0; $i < 6; $i++) {
            $resident = $this->makeUser($tenant, "Residente {$i}");
            $this->makeResidentInTower($tenant, $resident, $tower);
            $residents[] = $resident;
        }

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, ['target_type' => 'all']))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/publish")
            ->assertStatus(200);

        // The dispatcher received the whole resident audience.
        $recipients = $this->dispatcherSpy->lastCall()['recipients'] ?? [];
        foreach ($residents as $resident) {
            $this->assertContains($resident->id, $recipients, 'Every resident must be a recipient');
        }

        // One in-app row per resident (created by the bulk insert).
        $tenant->run(function () use ($residents, $admin): void {
            foreach ($residents as $resident) {
                $this->assertSame(1, NotificationUser::where('user_id', $resident->id)->count());
            }
            $this->assertSame(0, NotificationUser::where('user_id', $admin->id)->count(), 'A non-resident admin is not in the all audience');
            $this->assertSame(count($residents), NotificationUser::count(), 'Exactly one row per resident');
        });
    }

    public function test_board_target_reaches_only_current_board_members(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $boardMember = $this->makeUser($tenant, 'Mesa');
        $resident = $this->makeUser($tenant, 'Resi');

        $tower = $this->makeTower($tenant, 'Torre Unica');
        $this->makeResidentInTower($tenant, $resident, $tower);

        $tenant->run(function () use ($boardMember): void {
            $period = BoardPeriod::create([
                'name' => 'Mesa 2026',
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            BoardMember::create([
                'board_period_id' => $period->id,
                'user_id' => $boardMember->id,
                'board_role_id' => BoardRole::PRESIDENT,
            ]);
        });

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                'target_type' => 'board',
            ]))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/publish")
            ->assertStatus(200);

        $publishCall = $this->dispatcherSpy->lastCall();
        $this->assertSame([$boardMember->id], $publishCall['recipients'], 'Only the board member is notified');

        $memberBoard = $this->tenantRequest($this->residentToken($boardMember), $tenant)
            ->getJson('/api/announcement/board')->assertStatus(200)->json('data');
        $this->assertContains($ann['id'], array_column($memberBoard, 'id'), 'Board member sees the board-only announcement');

        $residentBoard = $this->tenantRequest($this->residentToken($resident), $tenant)
            ->getJson('/api/announcement/board')->assertStatus(200)->json('data');
        $this->assertNotContains($ann['id'], array_column($residentBoard, 'id'), 'Regular resident must NOT see board-only announcements');
    }

    // =========================================================================
    // B. Priority → channel matrix (§12.6)
    // =========================================================================

    public function test_priority_drives_channels_and_urgent_flag(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $resident = $this->makeUser($tenant, 'Resi');
        $tower = $this->makeTower($tenant, 'Torre Canales');
        $this->makeResidentInTower($tenant, $resident, $tower);

        $cases = [
            [AnnouncementPriority::NORMAL, ['log'], false],
            [AnnouncementPriority::IMPORTANT, ['log', 'blade_email'], false],
            // SMS disabled for now: URGENT no longer appends 'sms'. Email is the
            // designed template channel (blade_email), same as IMPORTANT.
            [AnnouncementPriority::URGENT, ['log', 'blade_email'], true],
        ];

        foreach ($cases as [$priorityId, $expectedChannels, $expectUrgent]) {
            $this->dispatcherSpy->calls = [];

            $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
                ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                    'announcement_priority_id' => $priorityId,
                ]))
                ->assertStatus(201)
                ->json('data');

            $this->tenantRequest($this->adminToken($admin), $tenant)
                ->postJson("/api/announcement/{$ann['id']}/publish")
                ->assertStatus(200);

            $call = $this->dispatcherSpy->lastCall();
            $this->assertNotNull($call, 'publish must dispatch');
            $this->assertSame($expectedChannels, $call['channels'], 'Channel matrix mismatch for priority');
            $this->assertSame('announcements', $call['data']['category'] ?? null);

            if ($expectUrgent) {
                $this->assertTrue($call['data']['urgent'] ?? false, 'URGENT must set data.urgent (quiet-hours bypass)');
            } else {
                $this->assertArrayNotHasKey('urgent', $call['data'], 'Non-urgent must not set data.urgent');
            }
        }
    }

    // =========================================================================
    // C. Scheduled publication + expiry archiving (cron)
    // =========================================================================

    public function test_scheduled_publish_stays_draft_and_cron_activates_notifying_once(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $resident = $this->makeUser($tenant, 'Resi');
        $tower = $this->makeTower($tenant, 'Torre Prog');
        $this->makeResidentInTower($tenant, $resident, $tower);

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                'publish_at' => now()->addHours(2)->toDateTimeString(),
            ]))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/publish")
            ->assertStatus(200);

        // Future publish_at: stays DRAFT, nothing dispatched (notifications-10).
        $tenant->run(function () use ($ann): void {
            $this->assertSame(AnnouncementStatus::DRAFT, Announcement::findOrFail($ann['id'])->announcement_status_id);
        });
        $this->assertCount(0, $this->dispatcherSpy->calls, 'Scheduled publish must NOT notify before activation');

        // Cron before due time → still nothing.
        Artisan::call('announcements:process-scheduled');
        $this->assertCount(0, $this->dispatcherSpy->calls);

        // Cron after due time → activates AND notifies exactly once.
        $this->travelTo(now()->addHours(3));
        Artisan::call('announcements:process-scheduled');

        $tenant->run(function () use ($ann): void {
            $this->assertSame(AnnouncementStatus::PUBLISHED, Announcement::findOrFail($ann['id'])->announcement_status_id);
        });
        $this->assertCount(1, $this->dispatcherSpy->calls, 'Activation must notify the audience');
        $this->assertContains($resident->id, $this->dispatcherSpy->lastCall()['recipients']);

        // Re-running the cron must never re-notify.
        Artisan::call('announcements:process-scheduled');
        $this->assertCount(1, $this->dispatcherSpy->calls, 'Cron must be idempotent — no double notification');

        $this->travelBack();
    }

    public function test_cron_archives_published_announcements_past_expiry(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $annId = null;
        $tenant->run(function () use (&$annId): void {
            $annId = Announcement::create([
                'title' => 'Aviso vencido',
                'content' => 'Contenido.',
                'announcement_type_id' => Catalog::id(AnnouncementType::class, 'Aviso general'),
                'announcement_priority_id' => AnnouncementPriority::NORMAL,
                'announcement_status_id' => AnnouncementStatus::PUBLISHED,
                'target_type' => 'all',
                'publish_at' => now()->subDays(10),
                'expires_at' => now()->subDay()->toDateString(),
            ])->id;
        });

        Artisan::call('announcements:process-scheduled');

        $tenant->run(function () use ($annId): void {
            $announcement = Announcement::findOrFail($annId);
            $this->assertSame(AnnouncementStatus::ARCHIVED, $announcement->announcement_status_id, 'Expired announcement must be archived');
            $this->assertNull($announcement->deleted_at, 'Archiving must never delete');
        });
    }

    // =========================================================================
    // D. Ownership (§12.2)
    // =========================================================================

    public function test_update_and_delete_are_author_only_with_president_bypass(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        AppRole::firstOrCreate(['name' => 'president', 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $author = $this->makeUser($tenant, 'Autora');
        $colleague = $this->makeUser($tenant, 'Colega');
        $president = $this->makeUser($tenant, 'Pres');
        $president->assignRole('president');

        $ann = $this->tenantRequest($this->creatorToken($author), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        // Another creator-role user with update/delete abilities cannot touch it.
        $this->tenantRequest($this->creatorToken($colleague), $tenant)
            ->putJson("/api/announcement/{$ann['id']}", ['title' => 'Hackeado'])
            ->assertStatus(403);

        $this->tenantRequest($this->creatorToken($colleague), $tenant)
            ->deleteJson("/api/announcement/{$ann['id']}")
            ->assertStatus(403);

        // The author can edit their own announcement.
        $this->tenantRequest($this->creatorToken($author), $tenant)
            ->putJson("/api/announcement/{$ann['id']}", ['title' => 'Título corregido'])
            ->assertStatus(200);

        // President (Spatie role) can delete someone else's announcement.
        $this->tenantRequest($this->creatorToken($president), $tenant)
            ->deleteJson("/api/announcement/{$ann['id']}")
            ->assertStatus(200);
    }

    public function test_store_forces_draft_status_and_server_side_author(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autora');
        $other = $this->makeUser($tenant, 'Otra');

        $ann = $this->tenantRequest($this->creatorToken($author), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                // Both must be IGNORED server-side (announcements-07/08).
                'announcement_status_id' => AnnouncementStatus::PUBLISHED,
                'author_user_id' => $other->id,
            ]))
            ->assertStatus(201)
            ->json('data');

        $tenant->run(function () use ($ann, $author): void {
            $fresh = Announcement::findOrFail($ann['id']);
            $this->assertSame(AnnouncementStatus::DRAFT, $fresh->announcement_status_id, 'Client must never choose the status — always born DRAFT');
            $this->assertSame($author->id, $fresh->author_user_id, 'Author must be the authenticated user, not the spoofed one');
        });
    }

    public function test_unknown_target_type_is_rejected_with_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autora');

        $this->tenantRequest($this->creatorToken($author), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                'target_type' => 'units',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_type']);
    }

    // =========================================================================
    // E. Edited published announcement (§12.4 r2 / §12.6)
    // =========================================================================

    public function test_editing_published_announcement_stamps_edited_at_and_notifies_readers(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autora');
        $reader = $this->makeUser($tenant, 'Lectora');
        $nonReader = $this->makeUser($tenant, 'NoLectora');

        $tower = $this->makeTower($tenant, 'Torre Edit');
        $this->makeResidentInTower($tenant, $reader, $tower);
        $this->makeResidentInTower($tenant, $nonReader, $tower);

        $ann = $this->tenantRequest($this->creatorToken($author), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->creatorToken($author), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/publish")
            ->assertStatus(200);

        $this->tenantRequest($this->residentToken($reader), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/read")
            ->assertStatus(200);

        $this->dispatcherSpy->calls = [];

        $this->tenantRequest($this->creatorToken($author), $tenant)
            ->putJson("/api/announcement/{$ann['id']}", ['content' => 'Contenido corregido tras publicación.'])
            ->assertStatus(200);

        $tenant->run(function () use ($ann): void {
            $this->assertNotNull(Announcement::findOrFail($ann['id'])->edited_at, 'Editing a published announcement must stamp edited_at');
        });

        $editCall = $this->dispatcherSpy->lastCall();
        $this->assertNotNull($editCall, 'Edit must notify prior readers');
        $this->assertStringContainsString('Aviso editado', $editCall['title']);
        $this->assertSame([$reader->id], $editCall['recipients'], 'Only users who already read it are notified of the edit');
    }

    // =========================================================================
    // F. Read metrics + is_read flag (§12.3.1 / §12.3.2)
    // =========================================================================

    public function test_read_metrics_and_board_is_read_flag(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $reader = $this->makeUser($tenant, 'Lectora');
        $other = $this->makeUser($tenant, 'Otra');

        $tower = $this->makeTower($tenant, 'Torre Metricas');
        $this->makeResidentInTower($tenant, $reader, $tower);
        $this->makeResidentInTower($tenant, $other, $tower);

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/publish")
            ->assertStatus(200);

        $this->tenantRequest($this->residentToken($reader), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/read")
            ->assertStatus(200);

        // "Visto por 1 de 2" (announcements-10).
        $metrics = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/metrics")
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(1, $metrics['reads_count']);
        $this->assertSame(2, $metrics['audience_count']);

        // Board exposes per-user read state.
        $readerBoard = collect($this->tenantRequest($this->residentToken($reader), $tenant)
            ->getJson('/api/announcement/board')->assertStatus(200)->json('data'))
            ->firstWhere('id', $ann['id']);
        $this->assertTrue((bool) $readerBoard['is_read'], 'Reader must see is_read=true');
        $this->assertSame(1, $readerBoard['reads_count']);

        $otherBoard = collect($this->tenantRequest($this->residentToken($other), $tenant)
            ->getJson('/api/announcement/board')->assertStatus(200)->json('data'))
            ->firstWhere('id', $ann['id']);
        $this->assertFalse((bool) $otherBoard['is_read'], 'Non-reader must see is_read=false');

        // Subsequent reads keep ONE row per user and refresh read_at (§12.5 step 7).
        $firstReadAt = null;
        $tenant->run(function () use ($ann, $reader, &$firstReadAt): void {
            $firstReadAt = AnnouncementRead::where('announcement_id', $ann['id'])
                ->where('user_id', $reader->id)->value('read_at');
        });

        $this->travelTo(now()->addMinutes(5));
        $this->tenantRequest($this->residentToken($reader), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/read")
            ->assertStatus(200);

        $tenant->run(function () use ($ann, $reader, $firstReadAt): void {
            $rows = AnnouncementRead::where('announcement_id', $ann['id'])->where('user_id', $reader->id)->get();
            $this->assertCount(1, $rows, 'Read tracking keeps one row per user');
            $this->assertTrue($rows->first()->read_at->gt($firstReadAt), 'read_at must refresh on re-open');
        });

        $this->travelBack();
    }

    // =========================================================================
    // G. Attachment cap: 6th file → 422 (§12.4 r6)
    // =========================================================================

    public function test_sixth_attachment_is_rejected_with_422(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        for ($i = 1; $i <= 5; $i++) {
            $this->tenantRequest($this->adminToken($admin), $tenant)
                ->post("/api/announcement/{$ann['id']}/media", [
                    'file' => $this->fakePdfFile("adjunto-{$i}.pdf"),
                ])
                ->assertStatus(201);
        }

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->post("/api/announcement/{$ann['id']}/media", [
                'file' => $this->fakePdfFile('adjunto-6.pdf'),
            ])
            ->assertStatus(422);

        $tenant->run(function () use ($ann): void {
            $announcement = Announcement::findOrFail($ann['id']);
            $this->assertSame(5, $announcement->getMedia('attachments')->count(), 'The original 5 attachments must remain untouched');
            $this->assertNotNull(
                $announcement->getMedia('attachments')->firstWhere('file_name', 'adjunto-1.pdf'),
                'The oldest attachment must NOT be silently rotated out'
            );
        });
    }

    // =========================================================================
    // H. MIME allowlist: only safe file types accepted (plan 020)
    // =========================================================================

    public function test_uploading_php_file_is_rejected_with_422(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        $maliciousFile = UploadedFile::fake()->create('malicious.php', 10, 'application/x-php');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->post("/api/announcement/{$ann['id']}/media", [
                'file' => $maliciousFile,
            ])
            ->assertStatus(422);
    }

    public function test_uploading_svg_file_is_rejected_with_422(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        $svgFile = UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->post("/api/announcement/{$ann['id']}/media", [
                'file' => $svgFile,
            ])
            ->assertStatus(422);
    }

    public function test_uploading_pdf_file_is_accepted_with_201(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');

        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->post("/api/announcement/{$ann['id']}/media", [
                'file' => $this->fakePdfFile('ok.pdf'),
            ])
            ->assertStatus(201);
    }

    // =========================================================================
    // I. Catalog search no longer references the dropped 'key' column
    // =========================================================================

    public function test_catalog_search_does_not_error_on_missing_key_column(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');

        foreach (['announcement_type', 'announcement_priority', 'announcement_status'] as $catalog) {
            $this->tenantRequest($this->adminToken($admin), $tenant)
                ->postJson("/api/{$catalog}/search", ['search' => ['value' => 'a']])
                ->assertStatus(200);
        }
    }

    // =========================================================================
    // J. Preview endpoint counts RESIDENTS (people), not units; handles 'board'
    // =========================================================================

    public function test_preview_counts_residents_for_all_by_tower_and_board(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $boardMember = $this->makeUser($tenant, 'Mesa');
        $insider = $this->makeUser($tenant, 'Torre A');
        $outsider = $this->makeUser($tenant, 'Torre B');

        $towerA = $this->makeTower($tenant, 'Torre A');
        $towerB = $this->makeTower($tenant, 'Torre B');
        $this->makeResidentInTower($tenant, $insider, $towerA);
        $this->makeResidentInTower($tenant, $boardMember, $towerA);
        $this->makeResidentInTower($tenant, $outsider, $towerB);

        $tenant->run(function () use ($boardMember): void {
            $period = BoardPeriod::create([
                'name' => 'Mesa 2026',
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'board_period_status_id' => BoardPeriodStatus::CURRENT,
            ]);

            BoardMember::create([
                'board_period_id' => $period->id,
                'user_id' => $boardMember->id,
                'board_role_id' => BoardRole::PRESIDENT,
            ]);
        });

        // 'all' → every active resident (3), NOT the non-resident admin, NOT unit count.
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement/preview', ['target_type' => 'all'])
            ->assertStatus(200)
            ->assertExactJson(['count' => 3]);

        // 'by_tower' Torre A → the two Torre A residents (insider + board member).
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement/preview', [
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$towerA->id]],
            ])
            ->assertStatus(200)
            ->assertExactJson(['count' => 2]);

        // 'board' → only the current board member, resolved via board members, not units.
        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement/preview', ['target_type' => 'board'])
            ->assertStatus(200)
            ->assertExactJson(['count' => 1]);
    }

    public function test_preview_rejects_unknown_target_type_with_422(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement/preview', ['target_type' => 'units'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_type']);
    }

    public function test_preview_is_forbidden_for_a_plain_resident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant, 'Resi');

        // A read-only resident token must NOT probe audience head-counts: preview is
        // gated by create.announcement, not read.announcement (which residents hold).
        $this->tenantRequest($this->residentToken($resident), $tenant)
            ->postJson('/api/announcement/preview', [
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [(string) Str::uuid()]],
            ])
            ->assertStatus(403);
    }

    // =========================================================================
    // K. Published email routes through the designed template + humanized audience
    // =========================================================================

    public function test_publish_routes_email_through_designed_template_with_audience_context(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $insider = $this->makeUser($tenant, 'Torre A');
        $towerA = $this->makeTower($tenant, 'Torre A');
        $this->makeResidentInTower($tenant, $insider, $towerA);

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                'announcement_priority_id' => AnnouncementPriority::IMPORTANT,
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$towerA->id]],
            ]))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson("/api/announcement/{$ann['id']}/publish")
            ->assertStatus(200);

        $call = $this->dispatcherSpy->lastCall();
        $this->assertNotNull($call, 'publish must dispatch');

        // Designed HTML email via the template channel; legacy plain-text + SMS are gone.
        $this->assertContains('blade_email', $call['channels'], 'important priority must route email through blade_email');
        $this->assertNotContains('sms', $call['channels'], 'SMS is disabled — never appended');
        $this->assertNotContains('resend_email', $call['channels'], 'legacy plain-text email channel must not be used');

        // email_template names the designed view; email_context carries NON-NULL render data.
        $this->assertSame('announcement-published', $call['data']['email_template'] ?? null);

        $context = $call['data']['email_context'] ?? [];
        $this->assertSame('Por torre: Torre A', $context['audienceLabel'] ?? null, 'humanized audience label');
        $this->assertSame($ann['title'], $context['title'] ?? null);
        $this->assertSame('Importante', $context['priorityLabel'] ?? null);
        $this->assertArrayHasKey('bodyText', $context);
        $this->assertArrayHasKey('announcementUrl', $context);
        $this->assertNotSame('', $context['bodyText'] ?? '');
        $this->assertNotSame('', $context['announcementUrl'] ?? '');
    }
}

/**
 * Records every dispatch() call so tests can assert on REAL recipients/channels
 * (the lax atLeastOnce() mock is exactly what let announcements-01 slip through).
 */
final class SpyNotificationDispatcher extends NotificationDispatcher
{
    /** @var array<int, array{recipients: array, title: string, body: string, data: array, channels: array}> */
    public array $calls = [];

    public function dispatch(
        array $recipients,
        string $title,
        string $body,
        array $data = [],
        array $channelNames = []
    ): void {
        $this->calls[] = [
            'recipients' => array_values($recipients),
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'channels' => $channelNames,
        ];
    }

    /**
     * @return array{recipients: array, title: string, body: string, data: array, channels: array}|null
     */
    public function lastCall(): ?array
    {
        return $this->calls === [] ? null : $this->calls[count($this->calls) - 1];
    }
}
