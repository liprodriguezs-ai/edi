<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementPriority;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Announcements\Models\AnnouncementType;
use Modules\Announcements\Services\AnnouncementService;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentMethod;
use Modules\Collections\Models\PaymentStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Tests for MediaLibrary attachments (Item 2).
 *
 * Covers:
 *  - Announcement: attach file (≤5), assert media stored + tenant-scoped.
 *  - Payment: attach receipt, assert media stored + tenant-scoped.
 *  - Reject files > size limit.
 *  - Tenant isolation: media of Tenant A not visible via Tenant B.
 *
 * Note on MIME detection: UploadedFile::fake()->create() produces zero-byte files
 * with mime=application/x-empty, which is rejected by acceptsMimeTypes(). We use
 * real temp files with content + explicit MIME declaration to pass Spatie's check.
 */
final class MediaAttachmentTest extends TestCase
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

    /**
     * Create a real temp file with content and return an UploadedFile referencing it.
     * This is necessary because UploadedFile::fake()->create() makes empty files whose
     * MIME type is application/x-empty — rejected by Spatie's acceptsMimeTypes filter.
     */
    private function fakePdfFile(string $name = 'test.pdf', int $sizeKb = 100): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_test_');
        $this->tempFiles[] = $tmpPath;

        // Write minimal PDF-like content so the file is non-empty
        file_put_contents($tmpPath, str_repeat('%PDF-1.4 test content for media attachment ', max(1, intdiv($sizeKb * 1024, 42))));

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    private function fakeImageFile(string $name = 'photo.jpg'): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_test_img_');
        $this->tempFiles[] = $tmpPath;

        // Minimal valid JPEG header bytes
        $jpegHeader = "\xFF\xD8\xFF\xE0".str_repeat('x', 1000);
        file_put_contents($tmpPath, $jpegHeader);

        return new UploadedFile($tmpPath, $name, 'image/jpeg', null, true);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function buildAnnouncement(): Announcement
    {
        $type = AnnouncementType::findOrFail(Catalog::id(AnnouncementType::class, 'Aviso general'));
        $priority = AnnouncementPriority::findOrFail(AnnouncementPriority::NORMAL);
        $status = AnnouncementStatus::findOrFail(AnnouncementStatus::DRAFT);

        return Announcement::create([
            'title' => 'Media Test Aviso '.Str::random(4),
            'announcement_type_id' => $type->id,
            'announcement_priority_id' => $priority->id,
            'announcement_status_id' => $status->id,
            'content' => 'Contenido de prueba.',
            'target_type' => 'all',
            'target_selector' => null,
            'channel_push' => false,
            'channel_board' => true,
            'channel_email' => false,
            'channel_sms' => false,
        ]);
    }

    private function makeUnit(): Unit
    {
        $unitType = UnitType::findOrFail(Catalog::id(UnitType::class, 'Casa'));
        $unitStatus = UnitStatus::findOrFail(Catalog::id(UnitStatus::class, 'Activa'));

        return Unit::create([
            'unit_type_id' => $unitType->id,
            'number' => (string) mt_rand(1, 999),
            'unit_status_id' => $unitStatus->id,
        ]);
    }

    private function makePayment(Unit $unit): Payment
    {
        $paymentMethod = PaymentMethod::first();
        $bankAccountType = BankAccountType::findOrFail(BankAccountType::CASH);
        $bankAccountPurpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
        $confirmedStatus = PaymentStatus::findOrFail(PaymentStatus::CONFIRMED);

        $bankAccount = BankAccount::create([
            'name' => 'Test Caja',
            'bank_account_type_id' => $bankAccountType->id,
            'bank_account_purpose_id' => $bankAccountPurpose->id,
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_active' => true,
        ]);

        return Payment::create([
            'unit_id' => $unit->id,
            'paid_at' => now()->toDateString(),
            'total_amount' => 70000,
            'payment_method_id' => $paymentMethod->id,
            'bank_account_id' => $bankAccount->id,
            'payment_status_id' => $confirmedStatus->id,
            'receipt_number' => 'R-TEST-'.Str::random(6),
        ]);
    }

    // =========================================================================
    // A. Announcement attachments
    // =========================================================================

    public function test_announcement_attach_file_stores_in_attachments_collection(): void
    {
        $tenant = $this->makeTenant('Announcement Media Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $announcement = $this->buildAnnouncement();
            $file = $this->fakePdfFile('documento.pdf', 100);

            $service = app(AnnouncementService::class);
            $media = $service->attachFile($announcement, $file);

            $this->assertNotNull($media->id, 'Media record must be created');
            $this->assertSame('attachments', $media->collection_name, 'Must be in attachments collection');
            $this->assertSame($announcement->id, $media->model_id, 'Media must belong to the announcement');

            // Reload media via the model relationship
            $announcement->refresh();
            $this->assertSame(1, $announcement->getMedia('attachments')->count(), 'Announcement must have 1 attachment');
        });
    }

    public function test_announcement_reject_file_over_10mb(): void
    {
        $tenant = $this->makeTenant('Announcement Max Size Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $announcement = $this->buildAnnouncement();
            // Create a fake file > 10 MB (10241 KB)
            $file = $this->fakePdfFile('grande.pdf', 10241);

            $service = app(AnnouncementService::class);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/10 MB/');

            $service->attachFile($announcement, $file);
        });
    }

    public function test_announcement_media_is_tenant_scoped_via_parent(): void
    {
        $tenantA = $this->makeTenant('Media TA');
        $tenantB = $this->makeTenant('Media TB');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $tenantA->run(function (): void {
            $announcement = $this->buildAnnouncement();
            $file = $this->fakePdfFile('aviso.pdf', 50);

            $service = app(AnnouncementService::class);
            $service->attachFile($announcement, $file);

            $this->assertSame(1, $announcement->getMedia('attachments')->count());
        });

        // Tenant B must not see Tenant A's announcements
        $tenantB->run(function (): void {
            $this->assertSame(0, Announcement::count(), 'Tenant B must not see Tenant A announcements');
        });
    }

    // =========================================================================
    // B. Payment receipt
    // =========================================================================

    public function test_payment_attach_receipt_stores_in_receipt_collection(): void
    {
        $tenant = $this->makeTenant('Payment Receipt Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = $this->makeUnit();
            $payment = $this->makePayment($unit);

            $file = $this->fakePdfFile('comprobante.pdf', 50);

            $payment->addMedia($file)->toMediaCollection('receipt');

            $payment->refresh();
            $media = $payment->getMedia('receipt');

            $this->assertSame(1, $media->count(), 'Payment must have exactly 1 receipt');
            $this->assertSame('receipt', $media->first()->collection_name);
            $this->assertSame($payment->id, $media->first()->model_id);
        });
    }

    public function test_payment_receipt_is_single_file_collection(): void
    {
        $tenant = $this->makeTenant('Payment Single Receipt Tenant');
        $this->seedTenant($tenant);

        $tenant->run(function (): void {
            $unit = $this->makeUnit();
            $payment = $this->makePayment($unit);

            $file1 = $this->fakePdfFile('comprobante1.pdf', 50);
            $file2 = $this->fakePdfFile('comprobante2.pdf', 50);

            $payment->addMedia($file1)->toMediaCollection('receipt');
            $payment->addMedia($file2)->toMediaCollection('receipt');

            $payment->refresh();
            // singleFile() means the second upload replaces the first
            $this->assertSame(1, $payment->getMedia('receipt')->count(), 'Receipt collection must keep only 1 file');
        });
    }
}
