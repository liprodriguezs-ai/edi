<?php

declare(strict_types=1);

namespace Tests\Feature\Announcements;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementRead;
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
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Feature tests for the split read analytics of an announcement:
 *  - GET /api/announcement/{id}/read-summary → the 5 aggregate counts.
 *  - GET /api/announcement/{id}/readers → a FLAT, server-paginated recipients list
 *    with read status (includes non-readers), filterable by status + search.
 *
 * Covers the readers happy path + shape, the read-summary counts, the board
 * (unit=null) branch, an empty audience, the ownership guard (403 on BOTH endpoints
 * for a non-author manager — the assertCanManage regression), and the status/search
 * filters.
 */
final class AnnouncementReadStatsTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.channels' => ['log']]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

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

    private function makeTower(Tenant $tenant, string $name): Tower
    {
        $tower = null;
        $tenant->run(function () use ($name, &$tower): void {
            $tower = Tower::create(['name' => $name, 'floors_count' => 3]);
        });

        return $tower;
    }

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

    private function markRead(Tenant $tenant, string $announcementId, User $user): void
    {
        $tenant->run(function () use ($announcementId, $user): void {
            AnnouncementRead::create([
                'announcement_id' => $announcementId,
                'user_id' => $user->id,
                'read_at' => now(),
            ]);
        });
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    /** @param array<string> $abilities */
    private function creatorToken(User $user, array $abilities = [
        'read.announcement',
        'create.announcement',
        'update.announcement',
        'delete.announcement',
    ]): string
    {
        return $user->createToken('creator', $abilities)->plainTextToken;
    }

    /** @return array<string, mixed> */
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

    // =========================================================================
    // A. readers — flat, paginated list with read status (readers + non-readers)
    // =========================================================================

    public function test_readers_returns_flat_paginated_list_with_read_status(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $reader = $this->makeUser($tenant, 'Residente Uno');
        $nonReader = $this->makeUser($tenant, 'Residente Dos');

        $tower = $this->makeTower($tenant, 'Torre A');
        $readerUnit = $this->makeResidentInTower($tenant, $reader, $tower);
        $this->makeResidentInTower($tenant, $nonReader, $tower);

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$tower->id]],
            ]))
            ->assertStatus(201)
            ->json('data');

        $this->markRead($tenant, $ann['id'], $reader);

        $res = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/readers")
            ->assertStatus(200);

        $res->assertJsonStructure([
            'data' => [
                ['user' => ['id', 'name'], 'unit' => ['id', 'full_identifier'], 'relationship', 'read', 'read_at'],
            ],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonCount(2, 'data');

        $rows = collect($res->json('data'));

        $readerRow = $rows->firstWhere('user.id', $reader->id);
        $this->assertNotNull($readerRow, 'The reader must be in the list');
        $this->assertTrue($readerRow['read']);
        $this->assertIsString($readerRow['read_at']);
        $this->assertSame('owner', $readerRow['relationship']);
        $this->assertSame($readerUnit->id, $readerRow['unit']['id']);
        $this->assertIsString($readerRow['unit']['full_identifier']);
        $this->assertNotSame('', $readerRow['unit']['full_identifier']);
        $this->assertNotSame('', $readerRow['user']['name']);

        $nonReaderRow = $rows->firstWhere('user.id', $nonReader->id);
        $this->assertNotNull($nonReaderRow, 'The non-reader must ALSO be listed (LEFT JOIN)');
        $this->assertFalse($nonReaderRow['read']);
        $this->assertNull($nonReaderRow['read_at']);
    }

    // =========================================================================
    // B. read-summary — the five aggregate counts
    // =========================================================================

    public function test_read_summary_reports_the_aggregate_counts(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $reader = $this->makeUser($tenant, 'Residente Uno');
        $nonReader = $this->makeUser($tenant, 'Residente Dos');

        $tower = $this->makeTower($tenant, 'Torre A');
        $this->makeResidentInTower($tenant, $reader, $tower);
        $this->makeResidentInTower($tenant, $nonReader, $tower);

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$tower->id]],
            ]))
            ->assertStatus(201)
            ->json('data');

        $this->markRead($tenant, $ann['id'], $reader);

        $res = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/read-summary")
            ->assertStatus(200);

        $res->assertJsonStructure([
            'target_units_count',
            'target_people_count',
            'read_people_count',
            'unread_people_count',
            'read_percentage',
        ])
            ->assertJsonPath('target_units_count', 2)
            ->assertJsonPath('target_people_count', 2)
            ->assertJsonPath('read_people_count', 1)
            ->assertJsonPath('unread_people_count', 1);

        $this->assertEqualsWithDelta(50.0, $res->json('read_percentage'), 0.05);
    }

    // =========================================================================
    // C. readers — board target lists members with a null unit
    // =========================================================================

    public function test_readers_board_target_lists_members_with_null_unit(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $boardMember = $this->makeUser($tenant, 'Mesa');

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

        $this->markRead($tenant, $ann['id'], $boardMember);

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/readers")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.unit.id', null)
            ->assertJsonPath('data.0.unit.full_identifier', 'Mesa directiva')
            ->assertJsonPath('data.0.relationship', 'board_member')
            ->assertJsonPath('data.0.user.id', $boardMember->id)
            ->assertJsonPath('data.0.read', true);
    }

    // =========================================================================
    // D. Empty audience — readers empty + summary zeros
    // =========================================================================

    public function test_empty_audience_returns_empty_readers_and_zero_summary(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // The admin author is NOT a resident, so 'all' resolves to zero people.
        $admin = $this->makeUser($tenant, 'Admin');

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, ['target_type' => 'all']))
            ->assertStatus(201)
            ->json('data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/readers")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');

        $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/read-summary")
            ->assertStatus(200)
            ->assertJsonPath('target_units_count', 0)
            ->assertJsonPath('target_people_count', 0)
            ->assertJsonPath('read_people_count', 0)
            ->assertJsonPath('unread_people_count', 0);
    }

    // =========================================================================
    // E. Ownership — non-author manager forbidden on BOTH endpoints
    // =========================================================================

    public function test_both_endpoints_are_forbidden_for_a_non_author_manager(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');
        $colleague = $this->makeUser($tenant, 'Colega');

        $ann = $this->tenantRequest($this->creatorToken($author), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant))
            ->assertStatus(201)
            ->json('data');

        // A colleague WITH update.announcement but who is NOT the author must be
        // blocked from both the summary and the readers list (resident PII).
        $this->tenantRequest($this->creatorToken($colleague), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/read-summary")
            ->assertStatus(403);

        $this->tenantRequest($this->creatorToken($colleague), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/readers")
            ->assertStatus(403);

        // The author reaches both.
        $this->tenantRequest($this->creatorToken($author), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/read-summary")
            ->assertStatus(200);

        $this->tenantRequest($this->creatorToken($author), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/readers")
            ->assertStatus(200);
    }

    // =========================================================================
    // F. readers — status + search filter the list server-side
    // =========================================================================

    public function test_readers_status_and_search_filters(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $admin = $this->makeUser($tenant, 'Admin');
        $reader = $this->makeUser($tenant, 'Lectora');
        $nonReader = $this->makeUser($tenant, 'Otro');

        $tower = $this->makeTower($tenant, 'Torre A');
        $this->makeResidentInTower($tenant, $reader, $tower);
        $this->makeResidentInTower($tenant, $nonReader, $tower);

        $ann = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->postJson('/api/announcement', $this->announcementPayload($tenant, [
                'target_type' => 'by_tower',
                'target_selector' => ['tower_ids' => [$tower->id]],
            ]))
            ->assertStatus(201)
            ->json('data');

        $this->markRead($tenant, $ann['id'], $reader);

        // status=unread → only the non-reader survives.
        $unread = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/readers?status=unread")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->json('data');

        $this->assertSame($nonReader->id, $unread[0]['user']['id']);
        $this->assertFalse($unread[0]['read']);

        // search=lectora → only the reader (name match), regardless of read state.
        $found = $this->tenantRequest($this->adminToken($admin), $tenant)
            ->getJson("/api/announcement/{$ann['id']}/readers?search=lectora")
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data')
            ->json('data');

        $this->assertSame($reader->id, $found[0]['user']['id']);
    }
}
