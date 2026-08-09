<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Collections\Models\AmountMode;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationUser;
use Modules\Projects\Models\CommunityProject;
use Modules\Projects\Models\CommunityProjectCategory;
use Modules\Projects\Models\CommunityProjectStatus;
use Modules\Projects\Models\ParticipationType;
use Modules\Projects\Models\ProjectContribution;
use Modules\Projects\Services\ContributionService;
use Modules\Projects\Services\ProjectActivationService;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * HTTP tests for the Community Projects privacy + docs + rich-text + reminder package:
 *  - ProjectContribution index/show privacy scoping (payments_visible_to_residents).
 *  - Manual "send reminder" endpoint (authorization, guards, recipients, disabled event).
 *  - Project document attachments (limit, size, extension, ownership).
 *  - Rich-text description sanitization on create.
 */
final class ProjectsPrivacyAndDocsTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['notifications.channels' => ['log']]);

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');
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

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(100, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function attachToUnit(Tenant $tenant, Unit $unit, User $user, bool $billingOwner = true): void
    {
        $tenant->run(function () use ($unit, $user, $billingOwner): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => $billingOwner,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProject(Tenant $tenant, array $overrides = [], bool $activate = true): CommunityProject
    {
        $project = null;
        $tenant->run(function () use (&$project, $overrides, $activate): void {
            $project = CommunityProject::create(array_merge([
                'title' => 'Proyecto '.Str::random(6),
                'description' => 'Proyecto de prueba',
                'community_project_category_id' => Catalog::id(CommunityProjectCategory::class, 'Obra'),
                'participation_type_id' => ParticipationType::VOLUNTARY,
                'amount_mode_id' => AmountMode::RANGE,
                'min_amount' => 20000,
                'ideal_amount' => 50000,
                'allow_decline' => true,
                'target_type' => 'all',
                'target_selector' => null,
                'goal_amount' => 1000000,
                'start_date' => '2026-04-01',
                'due_date' => '2026-12-31',
                'visible_to_residents' => true,
                'allow_comments' => true,
                'community_project_status_id' => CommunityProjectStatus::DRAFT,
            ], $overrides));

            if ($activate) {
                app(ProjectActivationService::class)->activate($project);
            }
        });

        return $project;
    }

    private function adminToken(User $user): string
    {
        return $user->createToken('admin', ['*'])->plainTextToken;
    }

    /**
     * @param  array<string>  $abilities
     */
    private function residentToken(User $user, array $abilities = ['read.community_project', 'read.project_contribution']): string
    {
        return $user->createToken('resident', $abilities)->plainTextToken;
    }

    /**
     * Build a tenant with $unitCount units (each with its own billing-owner resident)
     * and one project whose contributions cover every unit.
     *
     * @param  array<string, mixed>  $projectOverrides
     * @return array{tenant: Tenant, units: array<int, Unit>, residents: array<int, User>, project: CommunityProject}
     */
    private function scenario(int $unitCount = 2, array $projectOverrides = []): array
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $units = [];
        $residents = [];
        for ($i = 0; $i < $unitCount; $i++) {
            $unit = $this->makeUnit($tenant);
            $resident = $this->makeUser($tenant);
            $this->attachToUnit($tenant, $unit, $resident);
            $units[] = $unit;
            $residents[] = $resident;
        }

        $project = $this->makeProject($tenant, $projectOverrides);

        return compact('tenant', 'units', 'residents', 'project');
    }

    private function fakePdfFile(string $name = 'documento.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'proj_doc_test_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 project document ', 50));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    // =========================================================================
    // Privacy — index
    // =========================================================================

    public function test_index_with_flag_off_shows_resident_only_their_own_rows(): void
    {
        $scn = $this->scenario(2, ['payments_visible_to_residents' => false]);

        $residentIds = collect(
            $this->tenantRequest($this->residentToken($scn['residents'][0]), $scn['tenant'])
                ->getJson('/api/project_contribution')
                ->assertStatus(200)
                ->json('data')
        )->pluck('unit_id');

        $this->assertTrue($residentIds->contains($scn['units'][0]->id));
        $this->assertFalse($residentIds->contains($scn['units'][1]->id), 'flag off: foreign unit rows must be hidden');

        $admin = $this->makeUser($scn['tenant']);
        $adminIds = collect(
            $this->tenantRequest($this->adminToken($admin), $scn['tenant'])
                ->getJson('/api/project_contribution')
                ->assertStatus(200)
                ->json('data')
        )->pluck('unit_id');

        $this->assertTrue($adminIds->contains($scn['units'][0]->id));
        $this->assertTrue($adminIds->contains($scn['units'][1]->id), 'board sees every row');
    }

    public function test_index_with_flag_on_shows_resident_all_rows(): void
    {
        $scn = $this->scenario(2, ['payments_visible_to_residents' => true]);

        $ids = collect(
            $this->tenantRequest($this->residentToken($scn['residents'][0]), $scn['tenant'])
                ->getJson('/api/project_contribution')
                ->assertStatus(200)
                ->json('data')
        )->pluck('unit_id');

        $this->assertTrue($ids->contains($scn['units'][0]->id));
        $this->assertTrue($ids->contains($scn['units'][1]->id), 'flag on: residents see every row');
    }

    public function test_index_hides_rows_of_hidden_projects_even_with_flag_on(): void
    {
        // Project is active (so contributions exist) but not visible to residents.
        $scn = $this->scenario(2, [
            'payments_visible_to_residents' => true,
            'visible_to_residents' => false,
        ]);

        $rows = $this->tenantRequest($this->residentToken($scn['residents'][0]), $scn['tenant'])
            ->getJson('/api/project_contribution')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(0, $rows, 'a hidden project exposes no contribution rows to residents');
    }

    // =========================================================================
    // Privacy — show
    // =========================================================================

    public function test_show_foreign_row_is_404_with_flag_off_and_200_with_flag_on(): void
    {
        $off = $this->scenario(2, ['payments_visible_to_residents' => false]);
        $foreignId = $off['tenant']->run(
            fn () => ProjectContribution::where('community_project_id', $off['project']->id)
                ->where('unit_id', $off['units'][1]->id)
                ->value('id')
        );

        $this->tenantRequest($this->residentToken($off['residents'][0]), $off['tenant'])
            ->getJson("/api/project_contribution/{$foreignId}")
            ->assertStatus(404);

        $on = $this->scenario(2, ['payments_visible_to_residents' => true]);
        $visibleForeignId = $on['tenant']->run(
            fn () => ProjectContribution::where('community_project_id', $on['project']->id)
                ->where('unit_id', $on['units'][1]->id)
                ->value('id')
        );

        $this->tenantRequest($this->residentToken($on['residents'][0]), $on['tenant'])
            ->getJson("/api/project_contribution/{$visibleForeignId}")
            ->assertStatus(200);
    }

    // =========================================================================
    // Manual reminder
    // =========================================================================

    public function test_send_reminder_notifies_each_pending_unit_billing_owner(): void
    {
        $scn = $this->scenario(2);
        $admin = $this->makeUser($scn['tenant']);

        $this->tenantRequest($this->adminToken($admin), $scn['tenant'])
            ->postJson("/api/community-project/{$scn['project']->id}/send-contribution-reminder")
            ->assertStatus(200)
            ->assertJsonPath('data.sent', 2);

        $scn['tenant']->run(function () use ($scn): void {
            $reminderIds = Notification::where('title', 'Recordatorio de aportación')->pluck('id');
            $this->assertCount(2, $reminderIds);

            // Each pending unit's billing owner received exactly one reminder (the
            // count is scoped to the reminder notifications — every billing owner also
            // holds a project-activation notification).
            $this->assertSame(1, NotificationUser::whereIn('notification_id', $reminderIds)
                ->where('user_id', $scn['residents'][0]->id)->count());
            $this->assertSame(1, NotificationUser::whereIn('notification_id', $reminderIds)
                ->where('user_id', $scn['residents'][1]->id)->count());
        });
    }

    public function test_send_reminder_excludes_contributed_and_declined_units(): void
    {
        $scn = $this->scenario(3);

        $scn['tenant']->run(function () use ($scn): void {
            $contributed = ProjectContribution::where('community_project_id', $scn['project']->id)
                ->where('unit_id', $scn['units'][0]->id)->firstOrFail();
            app(ContributionService::class)->contribute($contributed, 20000);

            $declined = ProjectContribution::where('community_project_id', $scn['project']->id)
                ->where('unit_id', $scn['units'][1]->id)->firstOrFail();
            app(ContributionService::class)->decline($declined);
        });

        $admin = $this->makeUser($scn['tenant']);

        $this->tenantRequest($this->adminToken($admin), $scn['tenant'])
            ->postJson("/api/community-project/{$scn['project']->id}/send-contribution-reminder")
            ->assertStatus(200)
            ->assertJsonPath('data.sent', 1);

        $scn['tenant']->run(function () use ($scn): void {
            // Only the still-pending unit (index 2) is reminded.
            $reminderIds = Notification::where('title', 'Recordatorio de aportación')->pluck('id');
            $this->assertCount(1, $reminderIds);

            $this->assertSame(0, NotificationUser::whereIn('notification_id', $reminderIds)
                ->where('user_id', $scn['residents'][0]->id)->count());
            $this->assertSame(0, NotificationUser::whereIn('notification_id', $reminderIds)
                ->where('user_id', $scn['residents'][1]->id)->count());
            $this->assertSame(1, NotificationUser::whereIn('notification_id', $reminderIds)
                ->where('user_id', $scn['residents'][2]->id)->count());
        });
    }

    public function test_send_reminder_is_board_only_and_rejects_invalid_projects(): void
    {
        $scn = $this->scenario(2);

        // Resident (no update.community_project) → 403.
        $this->tenantRequest($this->residentToken($scn['residents'][0]), $scn['tenant'])
            ->postJson("/api/community-project/{$scn['project']->id}/send-contribution-reminder")
            ->assertStatus(403);

        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        // Mandatory project → 422.
        $mandatory = $this->makeProject($scn['tenant'], [
            'participation_type_id' => ParticipationType::MANDATORY,
            'amount_mode_id' => AmountMode::FIXED_REQUIRED,
            'fixed_amount' => 50000,
            'min_amount' => null,
            'ideal_amount' => null,
        ]);
        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson("/api/community-project/{$mandatory->id}/send-contribution-reminder")
            ->assertStatus(422);

        // Draft voluntary project → 422.
        $draft = $this->makeProject($scn['tenant'], [], activate: false);
        $this->tenantRequest($adminToken, $scn['tenant'])
            ->postJson("/api/community-project/{$draft->id}/send-contribution-reminder")
            ->assertStatus(422);
    }

    public function test_send_reminder_returns_200_with_zero_when_event_disabled(): void
    {
        $scn = $this->scenario(2);

        $scn['tenant']->run(function (): void {
            $event = NotificationEvent::findByKey('project_contribution_reminder');
            $setting = NotificationSetting::firstOrNew(['notification_event_id' => $event->id]);
            $setting->is_active = false;
            $setting->save();
        });

        $admin = $this->makeUser($scn['tenant']);

        $this->tenantRequest($this->adminToken($admin), $scn['tenant'])
            ->postJson("/api/community-project/{$scn['project']->id}/send-contribution-reminder")
            ->assertStatus(200)
            ->assertJsonPath('data.sent', 0);

        $scn['tenant']->run(function (): void {
            $this->assertSame(0, Notification::where('title', 'Recordatorio de aportación')->count());
        });
    }

    // =========================================================================
    // Documents
    // =========================================================================

    public function test_documents_upload_enforces_limit_size_and_extension(): void
    {
        $scn = $this->scenario(1);
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);
        $pid = $scn['project']->id;

        for ($i = 0; $i < 5; $i++) {
            $this->tenantRequest($adminToken, $scn['tenant'])
                ->post("/api/community-project/{$pid}/media", ['file' => $this->fakePdfFile("doc{$i}.pdf")])
                ->assertStatus(201);
        }

        // 6th document is rejected and the collection stays at 5 (no silent rotation).
        $this->tenantRequest($adminToken, $scn['tenant'])
            ->post("/api/community-project/{$pid}/media", ['file' => $this->fakePdfFile('doc6.pdf')])
            ->assertStatus(422);

        $scn['tenant']->run(function () use ($pid): void {
            $this->assertSame(5, CommunityProject::findOrFail($pid)->getMedia('documents')->count());
        });

        // Oversized file (> 10 MB) rejected by validation.
        $this->tenantRequest($adminToken, $scn['tenant'])
            ->post("/api/community-project/{$pid}/media", [
                'file' => UploadedFile::fake()->create('grande.pdf', 11 * 1024, 'application/pdf'),
            ])
            ->assertStatus(422);

        // Disallowed extension rejected by validation.
        $this->tenantRequest($adminToken, $scn['tenant'])
            ->post("/api/community-project/{$pid}/media", [
                'file' => UploadedFile::fake()->create('malicioso.exe', 10, 'application/octet-stream'),
            ])
            ->assertStatus(422);

        // Residents cannot upload documents.
        $this->tenantRequest($this->residentToken($scn['residents'][0]), $scn['tenant'])
            ->post("/api/community-project/{$pid}/media", ['file' => $this->fakePdfFile('resi.pdf')])
            ->assertStatus(403);
    }

    public function test_deleting_a_document_from_the_wrong_project_is_404(): void
    {
        $scn = $this->scenario(1);
        $other = $this->makeProject($scn['tenant']);
        $admin = $this->makeUser($scn['tenant']);
        $adminToken = $this->adminToken($admin);

        $mediaId = $this->tenantRequest($adminToken, $scn['tenant'])
            ->post("/api/community-project/{$scn['project']->id}/media", ['file' => $this->fakePdfFile('a.pdf')])
            ->assertStatus(201)
            ->json('data.id');

        // The media belongs to $scn['project'], not $other → 404.
        $this->tenantRequest($adminToken, $scn['tenant'])
            ->deleteJson("/api/community-project/{$other->id}/media/{$mediaId}")
            ->assertStatus(404);

        // It is still attached to its real project.
        $this->tenantRequest($adminToken, $scn['tenant'])
            ->deleteJson("/api/community-project/{$scn['project']->id}/media/{$mediaId}")
            ->assertStatus(200)
            ->assertJsonPath('data', true);
    }

    // =========================================================================
    // Rich text
    // =========================================================================

    public function test_create_sanitizes_rich_description_and_derives_plain_text(): void
    {
        $scn = $this->scenario(1);
        $admin = $this->makeUser($scn['tenant']);

        $data = $this->tenantRequest($this->adminToken($admin), $scn['tenant'])
            ->postJson('/api/community_project', [
                'title' => 'Proyecto con descripción rica',
                'community_project_category_id' => Catalog::id(CommunityProjectCategory::class, 'Obra'),
                'participation_type_id' => ParticipationType::VOLUNTARY,
                'amount_mode_id' => AmountMode::FREE,
                'target_type' => 'all',
                'goal_amount' => 100000,
                'description_html' => '<p>Hola <strong>mundo</strong></p><script>alert(1)</script>',
            ])
            ->assertStatus(201)
            ->json('data');

        $this->assertStringNotContainsString('<script', (string) ($data['description_html'] ?? ''));
        $this->assertStringContainsString('mundo', (string) $data['description_html']);

        $scn['tenant']->run(function () use ($data): void {
            $project = CommunityProject::findOrFail($data['id']);
            $this->assertStringNotContainsString('<script', (string) $project->description_html);
            $this->assertStringNotContainsString('<', (string) $project->description, 'description is the derived plain text');
            $this->assertStringContainsString('mundo', (string) $project->description);
        });
    }
}
