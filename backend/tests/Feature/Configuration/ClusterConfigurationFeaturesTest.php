<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Notifications\Concerns\NotifiesResidents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Collections\Models\PaymentMethod;
use Modules\Company\Models\Company;
use Modules\Configuration\Models\LegalDocument;
use Modules\Configuration\Services\TemplateRenderer;
use Modules\Notification\Models\Notification;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountType;
use Tests\Feature\E2E\ClusterScenario;
use Tests\TestCase;

/**
 * Module 12 — Cluster Configuration: the §16 feature batch.
 *
 * HTTP-level tests run on the real ClusterScenario (a fully-seeded tenant + real
 * logins carrying real role abilities), so the gates behave like production.
 *
 * Covers:
 *  - §16.3.1 company contact/identity fields round-trip + tenant isolation
 *  - §16.3.2 logo upload → main company media collection
 *  - §16.3.4 payment-methods-enabled + bank-data-visible + next folio
 *  - §16.3.4 enforcement: a disabled method is rejected (422)
 *  - §16.3.5 notification templates persist + is_active toggle
 *  - §16.3.8 versioned legal documents
 *  - §16.3.7 plan read + §16.4 r3 downgrade guard
 *  - §16.3.3 structure summary
 */
final class ClusterConfigurationFeaturesTest extends TestCase
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

    private function fakeImageFile(string $name = 'logo.png'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_cfg_img_');
        $this->tempFiles[] = $tmpPath;

        // Minimal valid PNG so the `image` rule + Spatie MIME check pass.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
        file_put_contents($tmpPath, $png);

        return new UploadedFile($tmpPath, $name, 'image/png', null, true);
    }

    private function fakePdfFile(string $name = 'reglamento.pdf'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_cfg_pdf_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 reglamento interno ', 100));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    private function fakePhpFile(string $name = 'shell.php'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_cfg_php_');
        $this->tempFiles[] = $tmpPath;
        file_put_contents($tmpPath, "<?php echo 'malicious'; ?>".str_repeat('x', 128));

        return new UploadedFile($tmpPath, $name, 'application/x-php', null, true);
    }

    // =========================================================================
    // §16.3.1 — Company contact + identity fields
    // =========================================================================

    public function test_company_contact_fields_round_trip_via_config_general(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']); // administrator

        $payload = [
            'phone' => '777-123-4567',
            'email' => 'admin@encinos.mx',
            'website' => 'https://encinos.mx',
            'short_name' => 'Encinos',
            'tagline' => 'Tu privada, organizada',
        ];

        $this->as($token, $tenant)->putJson('/api/config/general', $payload)->assertStatus(200);

        $get = $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->getJson('/api/config/general')
            ->assertStatus(200);

        foreach ($payload as $key => $value) {
            $this->assertSame($value, $get->json("data.{$key}"), "field {$key} must round-trip");
        }
    }

    /**
     * The cluster name is the label the whole app renders as the condominium, and
     * it lives on tenant.name — NOT on the company. Until this section exposed it,
     * only a super-admin could rename a cluster.
     */
    public function test_cluster_name_round_trips_and_updates_the_tenant(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']); // administrator

        $this->as($token, $tenant)
            ->putJson('/api/config/general', ['cluster_name' => '  Privada Los Encinos II  '])
            ->assertStatus(200)
            ->assertJsonPath('data.cluster_name', 'Privada Los Encinos II');

        $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->getJson('/api/config/general')
            ->assertStatus(200)
            ->assertJsonPath('data.cluster_name', 'Privada Los Encinos II');

        $this->assertDatabaseHas('tenant', [
            'id' => $tenant->getTenantKey(),
            'name' => 'Privada Los Encinos II',
        ]);

        // company.name is seeded from tenant.name, so it must not drift behind.
        $tenant->run(function (): void {
            $this->assertSame('Privada Los Encinos II', Company::query()->main()->first()->name);
        });
    }

    /**
     * legal_name and fiscal_address are NULLABLE columns, so null must be a legal
     * payload value. The rules said only ['sometimes','string'], which 422'd the
     * exact request the General section sends whenever those optional identity
     * fields are still blank — blocking every other edit in the section, the
     * cluster rename included.
     */
    public function test_general_config_accepts_null_for_nullable_company_fields(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        $this->as($token, $tenant)
            ->putJson('/api/config/general', [
                'cluster_name' => 'Privada Sin Razon Social',
                'legal_name' => null,
                'fiscal_address' => null,
                'rfc' => null,
                'phone' => null,
                'email' => null,
                'tagline' => null,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('tenant', [
            'id' => $tenant->getTenantKey(),
            'name' => 'Privada Sin Razon Social',
        ]);
    }

    public function test_blank_cluster_name_is_rejected_and_leaves_the_tenant_untouched(): void
    {
        $tenant = $this->scn['tenant'];
        $original = $tenant->fresh()->name;

        $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->putJson('/api/config/general', ['cluster_name' => '   '])
            ->assertStatus(422);

        $this->assertSame($original, $tenant->fresh()->name);
    }

    public function test_company_contact_fields_are_tenant_isolated(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        $this->as($token, $tenant)
            ->putJson('/api/config/general', ['short_name' => 'OnlyEncinos'])
            ->assertStatus(200);

        // A second cluster's main company must NOT pick up the first one's value.
        $other = $this->makeTenant('Otra Privada');
        $this->seedTenant($other);
        $other->run(function () use ($other): void {
            Company::create([
                'slug' => 'otra-'.uniqid(),
                'name' => 'Otra Privada',
                'main' => true,
                'tenant_id' => $other->getTenantKey(),
            ]);
        });

        $other->run(function (): void {
            $company = Company::query()->main()->first();
            $this->assertNotSame('OnlyEncinos', $company->short_name);
        });
    }

    public function test_invalid_email_and_website_are_rejected(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        $this->as($token, $tenant)
            ->putJson('/api/config/general', ['email' => 'not-an-email'])
            ->assertStatus(422);

        $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->putJson('/api/config/general', ['website' => 'not a url'])
            ->assertStatus(422);
    }

    public function test_general_config_write_is_gated_to_configuration_ability(): void
    {
        $tenant = $this->scn['tenant'];
        // Patricia is a resident: no update.configuration.
        $token = $this->login($this->scn['emails']['patricia']);

        $this->as($token, $tenant)
            ->putJson('/api/config/general', ['short_name' => 'Hackeo'])
            ->assertStatus(403);
    }

    // =========================================================================
    // §16.3.2 — Logo / cover upload
    // =========================================================================

    public function test_logo_upload_attaches_to_main_company_and_returns_url(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        $response = $this->as($token, $tenant)->post(
            '/api/config/branding/logo',
            ['file' => $this->fakeImageFile('logo.png')],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.logo_url'));

        // The media is on THIS tenant's main company.
        $tenant->run(function (): void {
            $company = Company::query()->main()->first();
            $this->assertSame(1, $company->getMedia('logo')->count());
        });
    }

    /**
     * The whole point of signing the URL: a browser rendering `<img src="…">`
     * sends no Authorization header, so the logo URL must resolve unauthenticated.
     * The previous URL pointed at the auth:sanctum-protected download route and
     * always rendered as a broken image.
     */
    public function test_logo_url_is_readable_without_authentication(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        $logoUrl = $this->as($token, $tenant)->post(
            '/api/config/branding/logo',
            ['file' => $this->fakeImageFile('logo.png')],
            ['Accept' => 'application/json']
        )->json('data.logo_url');

        $this->assertIsString($logoUrl);
        $this->assertStringContainsString('signature=', $logoUrl);

        // No token, no tenant header — exactly what an <img> tag sends.
        $this->get($logoUrl)->assertStatus(200);
    }

    public function test_cover_upload_route_no_longer_exists(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        $this->as($token, $tenant)->post(
            '/api/config/branding/cover',
            ['file' => $this->fakeImageFile('cover.png')],
            ['Accept' => 'application/json']
        )->assertStatus(404);
    }

    // =========================================================================
    // §16.3.4 — Collection settings: new fields
    // =========================================================================

    public function test_new_collection_settings_persist_and_read_back(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        $enabled = [PaymentMethod::CASH, PaymentMethod::TRANSFER];

        $this->as($token, $tenant)->putJson('/api/config/collection-settings', [
            'collections.payment_methods_enabled' => $enabled,
            'collections.next_physical_folio' => '0024',
        ])->assertStatus(200);

        $get = $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->getJson('/api/config/collection-settings')
            ->assertStatus(200);

        // The setting slugs contain literal dots, so read the data map directly
        // (json('data.collections.x') would treat the dot as nested traversal).
        $data = $get->json('data');
        $this->assertSame($enabled, $data['collections.payment_methods_enabled']);
        $this->assertSame('0024', $data['collections.next_physical_folio']);
    }

    public function test_bank_data_visible_requires_double_confirm(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        // Without double_confirm → blocked.
        $this->as($token, $tenant)->putJson('/api/config/collection-settings', [
            'collections.bank_data_visible_to_residents' => true,
        ])->assertStatus(422);

        // With double_confirm → persisted.
        $this->as($this->login($this->scn['emails']['laura']), $tenant)->putJson('/api/config/collection-settings', [
            'collections.bank_data_visible_to_residents' => true,
            'double_confirm' => true,
        ])->assertStatus(200);

        $tenant->run(function (): void {
            $this->assertTrue(Setting::getValue('collections.bank_data_visible_to_residents'));
        });
    }

    public function test_resident_bank_data_honors_visibility_flag(): void
    {
        $tenant = $this->scn['tenant'];

        // Default (flag off) → not visible.
        $token = $this->login($this->scn['emails']['patricia']);
        $this->as($token, $tenant)->getJson('/api/config/bank-data')
            ->assertStatus(200)
            ->assertJsonPath('visible', false);

        // Admin enables it.
        $this->as($this->login($this->scn['emails']['laura']), $tenant)->putJson('/api/config/collection-settings', [
            'collections.bank_data_visible_to_residents' => true,
            'double_confirm' => true,
        ])->assertStatus(200);

        // Resident now sees it.
        $this->as($this->login($this->scn['emails']['patricia']), $tenant)->getJson('/api/config/bank-data')
            ->assertStatus(200)
            ->assertJsonPath('visible', true);
    }

    // =========================================================================
    // §16.3.4 — Disabled payment method is rejected at payment register/submit
    // =========================================================================

    public function test_disabled_payment_method_is_rejected_on_register(): void
    {
        $tenant = $this->scn['tenant'];
        $unit = $this->scn['units']['casa1'];

        // Enable ONLY cash.
        $this->as($this->login($this->scn['emails']['laura']), $tenant)->putJson('/api/config/collection-settings', [
            'collections.payment_methods_enabled' => [PaymentMethod::CASH],
        ])->assertStatus(200);

        $bankAccountId = $this->makeBankAccount($tenant);

        // Treasurer tries to register a TRANSFER (disabled) → 422.
        $token = $this->login($this->scn['emails']['carmen']);
        $this->as($token, $tenant)->postJson('/api/payment/register', [
            'unit_id' => $unit->id,
            'paid_at' => '2026-06-01',
            'total_amount' => 50000,
            'payment_method_id' => PaymentMethod::TRANSFER,
            'bank_account_id' => $bankAccountId,
        ])->assertStatus(422);
    }

    private function makeBankAccount(Tenant $tenant): string
    {
        $id = '';
        $tenant->run(function () use (&$id): void {
            $type = BankAccountType::query()->first();
            $account = BankAccount::create([
                'name' => 'Caja',
                'bank_account_type_id' => $type->id,
                'bank' => 'HSBC',
                'account_number' => '1234',
                'clabe' => '012345678901234567',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
            $id = $account->id;
        });

        return $id;
    }

    // =========================================================================
    // §16.3.5 — Notification templates
    // =========================================================================

    public function test_default_templates_are_seeded_for_a_fresh_tenant(): void
    {
        $tenant = $this->scn['tenant'];

        $tenant->run(function (): void {
            $count = Setting::where('slug', 'like', 'notification_template.%')->count();
            $this->assertGreaterThanOrEqual(9, $count, 'default templates must be seeded per tenant');
        });
    }

    public function test_template_edit_persists_and_is_active_toggles(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        $this->as($token, $tenant)->putJson('/api/config/notification-templates', [
            'templates' => [
                [
                    'slug' => 'notification_template.charge_overdue',
                    'title' => 'Vencido!',
                    'body' => 'Hola {nombre_residente}, debes {monto}.',
                    'is_active' => false,
                ],
            ],
        ])->assertStatus(200);

        $get = $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->getJson('/api/config/notification-templates')
            ->assertStatus(200);

        $overdue = collect($get->json('data'))
            ->firstWhere('slug', 'notification_template.charge_overdue');

        $this->assertSame('Vencido!', $overdue['title']);
        $this->assertSame('Hola {nombre_residente}, debes {monto}.', $overdue['body']);
        $this->assertFalse($overdue['is_active']);
    }

    public function test_template_write_is_gated(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['patricia']); // resident

        $this->as($token, $tenant)->putJson('/api/config/notification-templates', [
            'templates' => [
                ['slug' => 'notification_template.charge_overdue', 'body' => 'x'],
            ],
        ])->assertStatus(403);
    }

    public function test_template_renderer_substitutes_from_setting(): void
    {
        $tenant = $this->scn['tenant'];

        $tenant->run(function (): void {
            $rendered = app(TemplateRenderer::class)->render(
                'notification_template.charge_overdue',
                ['count' => '3']
            );

            $this->assertNotNull($rendered);
            $this->assertTrue($rendered['is_active']);
            $this->assertStringContainsString('3', $rendered['body']);
            $this->assertStringNotContainsString('{count}', $rendered['body']);
        });
    }

    public function test_inactive_template_suppresses_dispatch_via_trait(): void
    {
        $tenant = $this->scn['tenant'];

        $tenant->run(function (): void {
            // Deactivate the template.
            Setting::updateOrCreate(
                ['slug' => 'notification_template.charge_overdue'],
                [
                    'name' => 'notification_template.charge_overdue',
                    'slug' => 'notification_template.charge_overdue',
                    'value' => ['title' => 'x', 'body' => 'y', 'is_active' => false],
                    'type' => Setting::TYPE_JSON,
                ]
            );

            $notifier = new class
            {
                use NotifiesResidents;

                /** @param array<int, string> $userIds */
                public function fire(array $userIds): void
                {
                    $this->notifyUsersFromTemplate(
                        $userIds,
                        'notification_template.charge_overdue',
                        ['nombre_residente' => 'Rosa'],
                        'Fallback title',
                        'Fallback body',
                    );
                }
            };

            $before = Notification::count();
            $notifier->fire([$this->scn['users']['patricia']->id]);

            $this->assertSame($before, Notification::count(), 'inactive template must suppress the notification');
        });
    }

    public function test_active_template_dispatches_via_trait(): void
    {
        $tenant = $this->scn['tenant'];

        $tenant->run(function (): void {
            $notifier = new class
            {
                use NotifiesResidents;

                /** @param array<int, string> $userIds */
                public function fire(array $userIds): void
                {
                    $this->notifyUsersFromTemplate(
                        $userIds,
                        'notification_template.charge_overdue',
                        ['nombre_residente' => 'Rosa', 'monto' => '700'],
                        'Fallback title',
                        'Fallback body',
                    );
                }
            };

            $before = Notification::count();
            $notifier->fire([$this->scn['users']['patricia']->id]);

            $this->assertSame($before + 1, Notification::count(), 'active template must dispatch the notification');
        });
    }

    // =========================================================================
    // §16.3.8 — Legal documents (versioned)
    // =========================================================================

    public function test_legal_document_upload_is_versioned_and_listed(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        // First upload → version 1.
        $first = $this->as($token, $tenant)->post('/api/config/legal', [
            'type' => LegalDocument::TYPE_REGLAMENTO,
            'title' => 'Reglamento Interno',
            'file' => $this->fakePdfFile('v1.pdf'),
        ], ['Accept' => 'application/json']);
        $first->assertStatus(201);
        $this->assertSame(1, $first->json('data.version'));
        $this->assertNotNull($first->json('data.file_url'));

        // Second upload of same type → version 2, supersedes v1.
        $second = $this->as($this->login($this->scn['emails']['laura']), $tenant)->post('/api/config/legal', [
            'type' => LegalDocument::TYPE_REGLAMENTO,
            'file' => $this->fakePdfFile('v2.pdf'),
        ], ['Accept' => 'application/json']);
        $second->assertStatus(201);
        $this->assertSame(2, $second->json('data.version'));

        $tenant->run(function (): void {
            $current = LegalDocument::where('type', LegalDocument::TYPE_REGLAMENTO)
                ->where('is_current', true)->get();
            $this->assertCount(1, $current, 'only one current version per type');
            $this->assertSame(2, $current->first()->version);
        });

        // List returns both versions.
        $list = $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->getJson('/api/config/legal')->assertStatus(200);
        $this->assertCount(2, $list->json('data'));
    }

    public function test_legal_document_upload_rejects_non_document_mime(): void
    {
        $tenant = $this->scn['tenant'];
        $token = $this->login($this->scn['emails']['laura']);

        // A .php disguised as a legal document must be rejected by the mimes rule.
        $this->as($token, $tenant)->post('/api/config/legal', [
            'type' => LegalDocument::TYPE_REGLAMENTO,
            'title' => 'Reglamento',
            'file' => $this->fakePhpFile('reglamento.php'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_resident_can_download_current_aviso_de_privacidad(): void
    {
        $tenant = $this->scn['tenant'];

        // Admin uploads aviso (defaults to resident-visible).
        $this->as($this->login($this->scn['emails']['laura']), $tenant)->post('/api/config/legal', [
            'type' => LegalDocument::TYPE_AVISO_PRIVACIDAD,
            'file' => $this->fakePdfFile('aviso.pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        // Resident downloads it.
        $this->as($this->login($this->scn['emails']['patricia']), $tenant)
            ->getJson('/api/config/legal/'.LegalDocument::TYPE_AVISO_PRIVACIDAD.'/current')
            ->assertStatus(200)
            ->assertJsonPath('data.type', LegalDocument::TYPE_AVISO_PRIVACIDAD);
    }

    public function test_legal_documents_are_tenant_scoped(): void
    {
        $tenant = $this->scn['tenant'];

        $this->as($this->login($this->scn['emails']['laura']), $tenant)->post('/api/config/legal', [
            'type' => LegalDocument::TYPE_ACTA_CONSTITUTIVA,
            'file' => $this->fakePdfFile('acta.pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(201);

        $other = $this->makeTenant('Otra Privada Legal');
        $this->seedTenant($other);

        $other->run(function (): void {
            $this->assertSame(0, LegalDocument::count(), 'legal docs must not leak across tenants');
        });
    }

    // =========================================================================
    // §16.3.3 — Structure summary
    // =========================================================================

    public function test_structure_summary_returns_counts_and_type(): void
    {
        $tenant = $this->scn['tenant'];

        $get = $this->as($this->login($this->scn['emails']['laura']), $tenant)
            ->getJson('/api/config/structure')->assertStatus(200);

        $this->assertSame('mixto', $get->json('data.type'));
        $this->assertSame(1, $get->json('data.towers_count'));
        $this->assertSame(1, $get->json('data.sections_count'));
        $this->assertSame(6, $get->json('data.units_count'));
    }
}
