<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Notifications\NotificationCategory;
use App\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Reports\Jobs\GenerateReportJob;
use Modules\Reports\Models\GeneratedReport;
use Modules\Reports\Models\ScheduledReport;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Wave-2 completion tests for the Reports module:
 *  - reports-05: resident async flow — owner can download their own generated
 *    report without read.report; foreign reports stay 403.
 *  - reports-10: scheduled_report store/update validation (key whitelist,
 *    frequency, format, recipients).
 *  - reports-11: completion email with download link for scheduled reports and
 *    failure notification to the owner.
 *  - reports-12: publish-to-board creates a published announcement with the
 *    report PDF attached, gated by read.report + create.announcement.
 */
final class ReportsCompletionTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private SpyReportsNotificationDispatcher $dispatcherSpy;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');

        $this->dispatcherSpy = new SpyReportsNotificationDispatcher;
        $this->app->instance(NotificationDispatcher::class, $this->dispatcherSpy);
    }

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
            $unit = Unit::create([
                'unit_type_id' => UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'))->id,
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'))->id,
            ]);
        });

        return $unit;
    }

    private function attachResident(Tenant $tenant, User $user, Unit $unit): void
    {
        $tenant->run(function () use ($user, $unit): void {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'start_date' => '2026-01-01',
            ]);
        });
    }

    /**
     * @param  array<int, string>  $abilities
     */
    private function token(User $user, array $abilities = ['*']): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    // =========================================================================
    // A. reports-05 — resident async download by ownership
    // =========================================================================

    public function test_resident_can_download_their_own_async_generated_report(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $resident = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $this->attachResident($tenant, $resident, $unit);

        $token = $this->token($resident, ['read.unit']); // NO read.report

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/unit_statement/generate?async=true&format=pdf', ['unit_id' => $unit->id])
            ->assertStatus(202);

        $reportId = $response->json('data.id');
        $this->assertNotNull($reportId);

        // Sync queue: the job already ran inline.
        $tenant->run(function () use ($reportId): void {
            $this->assertSame(
                GeneratedReport::STATUS_READY,
                GeneratedReport::findOrFail($reportId)->status,
            );
        });

        $this->tenantRequest($token, $tenant)
            ->get("/api/reports/generated/{$reportId}/download")
            ->assertStatus(200);
    }

    public function test_resident_cannot_download_a_report_they_did_not_request(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $resident = $this->makeUser($tenant);
        $unit = $this->makeUnit($tenant);
        $this->attachResident($tenant, $resident, $unit);

        $reportId = null;
        $tenant->run(function () use ($admin, &$reportId): void {
            Storage::put('reports/test/foreign.pdf', '%PDF-1.4 test');

            $report = GeneratedReport::create([
                'report_key' => 'account_balances',
                'format' => 'pdf',
                'config' => [],
                'status' => GeneratedReport::STATUS_READY,
                'file_path' => 'reports/test/foreign.pdf',
                'generated_by_user_id' => $admin->id,
                'expires_at' => now()->addDays(90),
            ]);

            $reportId = $report->id;
        });

        $token = $this->token($resident, ['read.unit']);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/reports/generated/{$reportId}/download")
            ->assertStatus(403);
    }

    public function test_read_report_holder_can_download_any_tenant_report(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $owner = $this->makeUser($tenant);
        $boardMember = $this->makeUser($tenant);

        $reportId = null;
        $tenant->run(function () use ($owner, &$reportId): void {
            Storage::put('reports/test/owned.pdf', '%PDF-1.4 test');

            $report = GeneratedReport::create([
                'report_key' => 'account_balances',
                'format' => 'pdf',
                'config' => [],
                'status' => GeneratedReport::STATUS_READY,
                'file_path' => 'reports/test/owned.pdf',
                'generated_by_user_id' => $owner->id,
                'expires_at' => now()->addDays(90),
            ]);

            $reportId = $report->id;
        });

        $token = $this->token($boardMember, ['read.report']);

        $this->tenantRequest($token, $tenant)
            ->get("/api/reports/generated/{$reportId}/download")
            ->assertStatus(200);
    }

    public function test_list_generated_shows_only_own_reports_without_read_report(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $resident = $this->makeUser($tenant);

        $tenant->run(function () use ($admin, $resident): void {
            foreach ([$admin, $resident] as $user) {
                GeneratedReport::create([
                    'report_key' => 'account_balances',
                    'format' => 'pdf',
                    'config' => [],
                    'status' => GeneratedReport::STATUS_READY,
                    'generated_by_user_id' => $user->id,
                    'expires_at' => now()->addDays(90),
                ]);
            }
        });

        $token = $this->token($resident, ['read.unit']); // NO read.report

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/reports/generated')
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($resident->id, $response->json('data.0.generated_by_user_id'));
    }

    // =========================================================================
    // B. reports-10 — scheduled_report validation
    // =========================================================================

    public function test_scheduled_report_store_rejects_invalid_payloads(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $token = $this->token($admin);

        // Unknown report key
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/scheduled_report', [
                'report_key' => 'invented_key',
                'frequency' => 'monthly',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['report_key']);

        // Invalid frequency (would leave next_run_at null → never runs)
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/scheduled_report', [
                'report_key' => 'delinquency',
                'frequency' => 'hourly',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['frequency']);

        // Recipient that is neither an email nor a tenant user UUID
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/scheduled_report', [
                'report_key' => 'delinquency',
                'frequency' => 'monthly',
                'recipients' => ['no-es-un-email'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipients.0']);

        // Unsupported format
        $this->tenantRequest($token, $tenant)
            ->postJson('/api/scheduled_report', [
                'report_key' => 'delinquency',
                'frequency' => 'monthly',
                'format' => 'xml',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['format']);
    }

    public function test_scheduled_report_store_accepts_valid_payload(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);
        $recipient = $this->makeUser($tenant);
        $token = $this->token($admin);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/scheduled_report', [
                'report_key' => 'delinquency',
                'frequency' => 'monthly',
                'format' => 'pdf',
                'recipients' => [$recipient->id, 'tesorero@cluster.mx'],
            ])
            ->assertStatus(201);

        $tenant->run(function (): void {
            $scheduled = ScheduledReport::firstOrFail();
            $this->assertNotNull($scheduled->next_run_at, 'next_run_at must be computed on store');
        });
    }

    // =========================================================================
    // C. reports-11 — completion email + failure notification
    // =========================================================================

    public function test_scheduled_completion_sends_email_with_download_link(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $recipient = $this->makeUser($tenant);

        $tenant->run(function () use ($recipient): void {
            ScheduledReport::create([
                'report_key' => ScheduledReport::KEY_ACCOUNT_BALANCES,
                'config' => [],
                'frequency' => ScheduledReport::FREQUENCY_MONTHLY,
                'format' => 'pdf',
                'recipients' => [$recipient->id],
                'next_run_at' => now()->subHour(),
                'is_active' => true,
            ]);
        });

        Artisan::call('reports:run-scheduled');

        $readyCall = collect($this->dispatcherSpy->calls)
            ->first(static fn (array $call): bool => $call['title'] === 'Reporte listo');

        $this->assertNotNull($readyCall, 'Scheduled completion must dispatch a "Reporte listo" notification');
        $this->assertContains('resend_email', $readyCall['channels'], 'Scheduled completion must go out by email (§15.8)');
        $this->assertContains($recipient->id, $readyCall['recipients']);
        $this->assertSame(NotificationCategory::REPORTS, $readyCall['data']['category'] ?? null);
        $this->assertStringContainsString('/download', (string) ($readyCall['data']['download_url'] ?? ''));

        $this->assertSame(
            1,
            NotificationUser::where('user_id', $recipient->id)->count(),
            'Recipient must also get the in-app record'
        );
    }

    public function test_failed_generation_notifies_owner_in_app_and_email(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $owner = $this->makeUser($tenant);

        $tenant->run(function () use ($owner): void {
            $report = GeneratedReport::create([
                'report_key' => 'broken_key_not_in_registry',
                'format' => 'pdf',
                'config' => [],
                'status' => GeneratedReport::STATUS_PENDING,
                'generated_by_user_id' => $owner->id,
                'expires_at' => now()->addDays(90),
            ]);

            GenerateReportJob::dispatchSync($report);

            $this->assertSame(GeneratedReport::STATUS_FAILED, $report->fresh()->status);
        });

        $failCall = collect($this->dispatcherSpy->calls)
            ->first(static fn (array $call): bool => $call['title'] === 'Falla en generación de reporte');

        $this->assertNotNull($failCall, 'A failed generation must notify the owner (§15.8)');
        $this->assertContains('resend_email', $failCall['channels']);
        $this->assertContains($owner->id, $failCall['recipients']);
        $this->assertSame(NotificationCategory::REPORTS, $failCall['data']['category'] ?? null);

        $this->assertSame(
            1,
            NotificationUser::where('user_id', $owner->id)->count(),
            'Owner must get the in-app failure record'
        );
    }

    // =========================================================================
    // D. reports-12 — publish to board
    // =========================================================================

    public function test_publish_to_board_creates_published_announcement_with_pdf(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $president = $this->makeUser($tenant);

        $token = $this->token($president, ['read.report', 'create.announcement']);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/reports/account_balances/publish-to-board', [])
            ->assertStatus(201);

        $tenant->run(function () use ($president): void {
            $announcement = Announcement::firstOrFail();

            $this->assertSame(AnnouncementStatus::PUBLISHED, $announcement->announcement_status_id);
            $this->assertSame('all', $announcement->target_type, 'Board publication targets the whole cluster');
            $this->assertSame($president->id, $announcement->author_user_id);
            $this->assertSame(1, $announcement->getMedia('attachments')->count(), 'The report PDF must be attached');
            $this->assertSame('application/pdf', $announcement->getFirstMedia('attachments')->mime_type);
        });
    }

    public function test_publish_to_board_requires_both_abilities(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);

        // read.report alone (e.g. a role without announcement authoring) → 403
        $this->tenantRequest($this->token($user, ['read.report']), $tenant)
            ->postJson('/api/reports/account_balances/publish-to-board', [])
            ->assertStatus(403);

        // create.announcement alone (cannot see the report data) → 403
        $this->tenantRequest($this->token($user, ['create.announcement']), $tenant)
            ->postJson('/api/reports/account_balances/publish-to-board', [])
            ->assertStatus(403);

        $tenant->run(function (): void {
            $this->assertSame(0, Announcement::count(), 'No announcement may be created without both abilities');
        });
    }

    public function test_publish_to_board_rejects_unknown_report_key(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $admin = $this->makeUser($tenant);

        $this->tenantRequest($this->token($admin), $tenant)
            ->postJson('/api/reports/invented_key/publish-to-board', [])
            ->assertStatus(404);
    }
}

/**
 * Records every dispatch() call so tests can assert on real recipients/channels
 * (same pattern as AnnouncementsHttpTest's spy).
 */
final class SpyReportsNotificationDispatcher extends NotificationDispatcher
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
}
