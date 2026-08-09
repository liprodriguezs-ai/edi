<?php

declare(strict_types=1);

namespace Tests\Feature\Complaint;

use App\Models\Role as AppRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\ChatService;
use Modules\Complaint\Models\ComplaintCategory;
use Modules\Complaint\Models\ComplaintPriority;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for complaint registration (spec "Registration Gate" + "Case
 * Number Generation" + "Evidence Attachments"):
 *
 *  - Board member registers a complaint (201, reporter_user_id stamped)
 *  - Coarse Spatie gate denies a resident lacking create.complaint (403)
 *  - Runtime complaint.reporter_role_keys gate denies a role EXCLUDED from
 *    the tenant config even though its Spatie permission would allow it
 *  - complaint.requires_evidence gates creation (422 without a file, 201 with)
 *  - case_number increments sequentially within a year
 */
final class ComplaintRegistrationTest extends TestCase
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

    private function makeUser(Tenant $tenant, string $role): User
    {
        $user = User::create([
            'name' => 'Test '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => bcrypt('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        AppRole::firstOrCreate(['name' => $role, 'guard_name' => 'api']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string> $abilities */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    /** @return array<string, string> */
    private function catalogIds(Tenant $tenant): array
    {
        $ids = [];
        $tenant->run(function () use (&$ids): void {
            $ids['category_id'] = ComplaintCategory::first()->id;
            $ids['priority_id'] = ComplaintPriority::first()->id;
        });

        return $ids;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $catalog, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Ruido excesivo',
            'complaint_category_id' => $catalog['category_id'],
            'complaint_priority_id' => $catalog['priority_id'],
            'against_description' => 'Vecino con música alta después de medianoche',
        ], $overrides);
    }

    // =========================================================================
    // Registration Gate
    // =========================================================================

    public function test_board_member_can_register_complaint(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);

        $response = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertCreated();

        $response->assertJsonPath('data.reporter_user_id', $president->id);
        $this->assertNotEmpty($response->json('data.case_number'));
    }

    public function test_resident_without_permission_denied(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant, 'resident');
        $catalog = $this->catalogIds($tenant);

        // Resident's Sanctum token lacks create.complaint entirely — the coarse
        // Orion/Spatie gate denies before ComplaintService::register runs.
        $this->tenantRequest($this->token($resident, ['read.complaint']), $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertStatus(403);
    }

    /**
     * Runtime gate (ComplaintService::register, spec Requirement 1): even a role
     * that HOLDS the coarse create.complaint Spatie permission (vocal, by
     * default a reporter role) must be denied once the tenant admin narrows
     * complaint.reporter_role_keys to exclude it — the two gates are orthogonal
     * (design ADR-5), so a coarse-permission pass must never bypass the
     * per-tenant config check.
     */
    public function test_runtime_reporter_role_keys_denies_role_excluded_from_config(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $vocal = $this->makeUser($tenant, 'vocal');
        $catalog = $this->catalogIds($tenant);

        $tenant->run(function (): void {
            Setting::where('slug', 'complaint.reporter_role_keys')
                ->update(['value' => json_encode(['president'])]);
        });

        $this->tenantRequest($this->token($vocal, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertStatus(403);
    }

    // =========================================================================
    // Registration Eligibility Endpoint (UX fix: hide "Registrar incidencia" for
    // a role that would be rejected by the runtime reporter_role_keys gate)
    // =========================================================================

    public function test_registration_eligibility_true_for_a_default_reporter_role(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');

        $this->tenantRequest($this->token($president, ['read.complaint']), $tenant)
            ->getJson('/api/complaint/registration-eligibility')
            ->assertOk()
            ->assertJsonPath('data.can_register', true);
    }

    public function test_registration_eligibility_true_for_administrator_under_default_config(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        // complaint.reporter_role_keys default includes "administrator"
        // (user decision, 2026-07-19) — mirrors DEFAULT_REPORTER_ROLE_KEYS
        // in ComplaintService.
        $administrator = $this->makeUser($tenant, 'administrator');

        $this->tenantRequest($this->token($administrator, ['read.complaint']), $tenant)
            ->getJson('/api/complaint/registration-eligibility')
            ->assertOk()
            ->assertJsonPath('data.can_register', true);
    }

    public function test_registration_eligibility_false_for_a_role_not_in_reporter_keys(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeUser($tenant, 'resident');

        $this->tenantRequest($this->token($resident, ['read.complaint']), $tenant)
            ->getJson('/api/complaint/registration-eligibility')
            ->assertOk()
            ->assertJsonPath('data.can_register', false);
    }

    public function test_registration_eligibility_false_for_administrator_once_tenant_reduces_reporter_keys(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $administrator = $this->makeUser($tenant, 'administrator');

        $tenant->run(function (): void {
            Setting::where('slug', 'complaint.reporter_role_keys')
                ->update(['value' => json_encode(['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee'])]);
        });

        $this->tenantRequest($this->token($administrator, ['read.complaint']), $tenant)
            ->getJson('/api/complaint/registration-eligibility')
            ->assertOk()
            ->assertJsonPath('data.can_register', false);
    }

    // =========================================================================
    // Evidence Attachments
    // =========================================================================

    public function test_evidence_required_by_config_rejects_missing_evidence(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);

        $tenant->run(function (): void {
            Setting::where('slug', 'complaint.requires_evidence')
                ->update(['value' => 'true']);
        });

        $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertStatus(422);
    }

    public function test_evidence_required_by_config_accepts_with_evidence(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);

        $tenant->run(function (): void {
            Setting::where('slug', 'complaint.requires_evidence')
                ->update(['value' => 'true']);
        });

        // UploadedFile::fake()->image() produces real GD-encoded bytes (unlike
        // ->create(), which is a zero-byte application/x-empty stub rejected by
        // Spatie's acceptsMimeTypes) — see tests/Feature/Media/MediaAttachmentTest.
        $evidence = UploadedFile::fake()->image('evidencia.jpg', 100, 100);

        $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->post('/api/complaint', array_merge($this->payload($catalog), ['evidence' => $evidence]))
            ->assertCreated();
    }

    public function test_evidence_optional_by_default(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);

        // complaint.requires_evidence default is false — no override needed.
        $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertCreated();
    }

    // =========================================================================
    // Case Number Generation
    // =========================================================================

    public function test_case_number_increments_sequentially_within_a_year(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);
        $token = $this->token($president, ['create.complaint']);

        $first = $this->tenantRequest($token, $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertCreated()
            ->json('data.case_number');

        $second = $this->tenantRequest($token, $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertCreated()
            ->json('data.case_number');

        $year = now()->year;
        $this->assertSame(sprintf('D-%04d-000001', $year), $first);
        $this->assertSame(sprintf('D-%04d-000002', $year), $second);
    }

    // =========================================================================
    // Reports Channel Notice
    // =========================================================================

    public function test_registering_posts_minimal_clickable_notice_to_reports_channel_by_default(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);

        $complaintId = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertCreated()
            ->json('data.id');

        $tenant->run(function () use ($complaintId, $catalog): void {
            $categoryName = ComplaintCategory::find($catalog['category_id'])->name;
            $channel = ChatChannel::where('name', ChatService::REPORTS_CHANNEL_NAME)->firstOrFail();

            $message = ChatMessage::where('chat_channel_id', $channel->id)
                ->where('body_html', 'like', "%/complaint/{$complaintId}%")
                ->first();

            $this->assertNotNull($message, 'Expected a Reportes y sugerencias notice for the registered complaint');
            // Plain-text fallback is still present (derived from body_html server-side).
            $this->assertNotNull($message->body);
            $this->assertStringContainsString($categoryName, $message->body);
            // Clickable deep link to the complaint detail lands in body_html.
            $this->assertStringContainsString('href="', (string) $message->body_html);
            $this->assertStringContainsString('/complaint/'.$complaintId, (string) $message->body_html);
            // Confidentiality: NO title, NO denounced description leaked into the notice.
            $this->assertStringNotContainsString('Ruido excesivo', $message->body);
            $this->assertStringNotContainsString('Vecino con música alta', $message->body);
        });
    }

    public function test_registering_does_not_post_notice_when_setting_disabled(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);

        $tenant->run(function (): void {
            Setting::updateOrCreate(
                ['slug' => 'complaint.post_to_reports_channel'],
                ['name' => 'complaint.post_to_reports_channel', 'value' => false, 'type' => Setting::TYPE_BOOLEAN]
            );
        });

        $complaintId = $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertCreated()
            ->json('data.id');

        $tenant->run(function () use ($complaintId): void {
            $channel = ChatChannel::where('name', ChatService::REPORTS_CHANNEL_NAME)->firstOrFail();

            $this->assertDatabaseMissing('chat_message', [
                'chat_channel_id' => $channel->id,
            ]);

            $this->assertNull(
                ChatMessage::where('chat_channel_id', $channel->id)
                    ->where('body_html', 'like', "%/complaint/{$complaintId}%")
                    ->first()
            );
        });
    }

    public function test_registering_succeeds_even_when_reports_channel_missing(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            ChatChannel::where('name', ChatService::REPORTS_CHANNEL_NAME)->delete();
        });

        $president = $this->makeUser($tenant, 'president');
        $catalog = $this->catalogIds($tenant);

        // Registration itself must never fail because the Reportes y sugerencias
        // channel is gone.
        $this->tenantRequest($this->token($president, ['create.complaint']), $tenant)
            ->postJson('/api/complaint', $this->payload($catalog))
            ->assertCreated();
    }
}
