<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Models\Audit;
use Modules\Company\Models\Company;
use Modules\Configuration\Models\LegalDocument;
use Modules\Tenant\Models\Tenant;
use Tests\Feature\E2E\ClusterScenario;
use Tests\TestCase;

/**
 * Wave 2 — SaaS self-administration + configuration integrity:
 *
 *  - configuration-04: GET /api/config/subscription + POST change-plan, gated by
 *    manage.subscription (administrator ✅ / treasurer ❌), validating the target
 *    plan and re-syncing the tenant's systems from the new plan.
 *  - configuration-07: §16.4 r5 — Tenant operational changes audited with
 *    old/new values; Company edits stamp user_updated_id (GlobalObserver).
 *  - configuration-08: §16.3.8 — only ONE current legal document per
 *    (tenant, type), enforced transactionally and by a partial unique index.
 */
final class SubscriptionSelfServiceTest extends TestCase
{
    use ClusterScenario;
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string, mixed> */
    private array $scn;

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // PHPUnit runs in console: owen-it only registers its observer at model
        // boot when audit.console is true. Must be set BEFORE the first Tenant /
        // BaseModel usage (buildScenario) so the audit assertions are real.
        config(['audit.console' => true]);

        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('private');
        $this->scn = $this->buildScenario();
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

    private function fakePdfFile(string $name = 'doc.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_sub_pdf_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 documento legal ', 100));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    // =========================================================================
    // configuration-04 — GET /api/config/subscription
    // =========================================================================

    public function test_subscription_overview_is_denied_to_treasurer(): void
    {
        $tenant = $this->scn['tenant'];

        // Carmen is the treasurer: read.configuration yes, manage.subscription no.
        $this->as($this->login($this->scn['emails']['carmen']), $tenant)
            ->getJson('/api/config/subscription')
            ->assertStatus(403);
    }

    // =========================================================================
    // configuration-07 — §16.4 r5: audit with old/new values
    // =========================================================================

    public function test_operational_config_change_writes_audit_with_old_and_new_values(): void
    {
        $tenant = $this->scn['tenant'];
        $tenant->update(['type' => 'horizontal']);

        $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->putJson('/api/config/operational', ['type' => 'vertical'])
            ->assertStatus(200);

        $audits = Audit::query()
            ->withoutGlobalScopes()
            ->where('auditable_type', Tenant::class)
            ->where('auditable_id', $tenant->id)
            ->where('event', 'updated')
            ->get();

        $audit = $audits->first(
            fn (Audit $row): bool => ($row->new_values['type'] ?? null) === 'vertical'
        );

        $this->assertNotNull($audit, 'the tenant config change must create an audit row with the new value');
        $this->assertSame('horizontal', $audit->old_values['type'] ?? null, 'old value must be recorded');
        $this->assertArrayNotHasKey('data', $audit->new_values, 'the stancl virtual data column must be excluded');
    }

    /** R-TCUR-3: only the curated list (MXN/USD) is accepted for tenant currency. */
    public function test_operational_currency_must_be_in_curated_list(): void
    {
        $tenant = $this->scn['tenant'];
        $tenant->update(['currency' => 'MXN']);

        // Confirmed is sent so the 422 is attributable to the Rule::in validation
        // failure, not the separate timezone/currency confirmation gate.
        $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->putJson('/api/config/operational', [
                'currency' => 'EUR',
                'confirmed' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('currency');

        $tenant->refresh();
        $this->assertSame('MXN', $tenant->currency, 'an invalid currency must never persist');
    }

    public function test_operational_currency_accepts_curated_values_with_confirmation(): void
    {
        $tenant = $this->scn['tenant'];
        $tenant->update(['currency' => 'MXN']);

        $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->putJson('/api/config/operational', [
                'currency' => 'USD',
                'confirmed' => true,
            ])
            ->assertStatus(200);

        $tenant->refresh();
        $this->assertSame('USD', $tenant->currency, 'a curated currency persists once confirmed');
    }

    public function test_company_general_update_stamps_user_updated_id(): void
    {
        $tenant = $this->scn['tenant'];
        $laura = $this->scn['users']['laura'];

        $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->putJson('/api/config/general', ['short_name' => 'Encinos Audit'])
            ->assertStatus(200);

        $tenant->run(function () use ($laura): void {
            $company = Company::query()->main()->first();
            $this->assertSame('Encinos Audit', $company->short_name);
            $this->assertSame($laura->id, $company->user_updated_id, 'GlobalObserver must stamp the acting user');
        });
    }

    // =========================================================================
    // configuration-08 — §16.3.8: single current legal document per type
    // =========================================================================

    public function test_consecutive_uploads_keep_exactly_one_current_version(): void
    {
        $tenant = $this->scn['tenant'];

        $first = $this->as($this->login($this->scn['emails']['laura']), $tenant)->post('/api/config/legal', [
            'type' => LegalDocument::TYPE_REGLAMENTO,
            'file' => $this->fakePdfFile('v1.pdf'),
        ], ['Accept' => 'application/json']);
        $first->assertStatus(201);

        $second = $this->as($this->login($this->scn['emails']['laura']), $tenant)->post('/api/config/legal', [
            'type' => LegalDocument::TYPE_REGLAMENTO,
            'file' => $this->fakePdfFile('v2.pdf'),
        ], ['Accept' => 'application/json']);
        $second->assertStatus(201);
        $this->assertSame(2, $second->json('data.version'));

        $tenant->run(function () use ($first): void {
            $current = LegalDocument::where('type', LegalDocument::TYPE_REGLAMENTO)
                ->where('is_current', true)
                ->get();

            $this->assertCount(1, $current, 'exactly one current version per type');
            $this->assertSame(2, $current->first()->version);

            $superseded = LegalDocument::find($first->json('data.id'));
            $this->assertFalse($superseded->is_current, 'the first version must be superseded');
        });
    }

    public function test_partial_unique_index_blocks_a_second_current_row(): void
    {
        $tenant = $this->scn['tenant'];

        $tenant->run(function (): void {
            LegalDocument::create([
                'type' => LegalDocument::TYPE_REGLAMENTO,
                'version' => 1,
                'is_current' => true,
                'is_visible_to_residents' => false,
                'published_at' => now(),
            ]);

            $this->expectException(QueryException::class);

            // Bypassing the endpoint: the DB itself must refuse a second current
            // row of the same (tenant, type) — the race-condition backstop.
            LegalDocument::create([
                'type' => LegalDocument::TYPE_REGLAMENTO,
                'version' => 2,
                'is_current' => true,
                'is_visible_to_residents' => false,
                'published_at' => now(),
            ]);
        });
    }
}
