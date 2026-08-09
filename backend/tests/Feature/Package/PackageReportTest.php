<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Models\Role as AppRole;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Package\Http\Controllers\PackageReportController;
use Modules\Package\Models\Package;
use Modules\Package\Models\PackageStatus;
use Modules\Tenant\Models\Tenant;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Coverage for PackageReportController (WU-5 / package-reporting spec):
 *
 *  - GET /api/package/report/aging         → buckets, tenant isolation, permission gate
 *  - GET /api/package/report/aging/export  → xlsx content-type, disposition header, row count
 *  - GET /api/package/report/pending-count → totals, d7_plus, zero case
 */
final class PackageReportTest extends TestCase
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

        Carbon::setTestNow();

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Create a user with a Spatie role and attach to the given tenant. */
    private function makeRoleUser(Tenant $tenant, string $role): User
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

    /**
     * @param  array<string>  $abilities
     */
    private function token(User $user, array $abilities): string
    {
        return $user->createToken('test', $abilities)->plainTextToken;
    }

    private function makeUnit(Tenant $tenant): Unit
    {
        $unit = null;
        $tenant->run(function () use (&$unit): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Casa'),
                'number' => (string) mt_rand(1, 99999),
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);
        });

        return $unit;
    }

    /**
     * Create a Package directly inside the tenant context with precise date control.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createPackage(Tenant $tenant, Unit $unit, array $overrides = []): Package
    {
        $pkg = null;
        $tenant->run(function () use ($unit, $overrides, &$pkg): void {
            $pkg = Package::create(array_merge([
                'unit_id' => $unit->id,
                'package_status_id' => PackageStatus::READY,
                'received_at' => now(),
            ], $overrides));
        });

        return $pkg;
    }

    // =========================================================================
    // Aging — bucket placement (5.3.1 item 1)
    // =========================================================================

    public function test_aging_places_packages_in_correct_buckets(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        // P1: 1 day old → d0_2
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(1)]);
        // P2: 5 days old → d3_7
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(5)]);
        // P3: 10 days old → d7_plus
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(10)]);
        // P4: 2 days old, PICKED_UP — must not appear in any bucket
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(2),
            'package_status_id' => PackageStatus::PICKED_UP,
        ]);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $this->tenantRequest($this->token($admin, ['read.report_package']), $tenant)
            ->getJson('/api/package/report/aging')
            ->assertOk()
            ->assertJsonPath('total_pending', 3)
            ->assertJsonPath('buckets.d0_2.count', 1)
            ->assertJsonPath('buckets.d3_7.count', 1)
            ->assertJsonPath('buckets.d7_plus.count', 1);
    }

    // =========================================================================
    // Aging — tenant isolation (5.3.1 item 2)
    // =========================================================================

    public function test_aging_is_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);

        // Tenant A: 3 pending packages
        $this->createPackage($tenantA, $unitA, ['received_at' => now()->subDays(1)]);
        $this->createPackage($tenantA, $unitA, ['received_at' => now()->subDays(3)]);
        $this->createPackage($tenantA, $unitA, ['received_at' => now()->subDays(9)]);

        // Tenant B: 5 pending packages
        for ($i = 0; $i < 5; $i++) {
            $this->createPackage($tenantB, $unitB, ['received_at' => now()->subDays(1)]);
        }

        $adminA = $this->makeRoleUser($tenantA, 'administrator');

        $this->tenantRequest($this->token($adminA, ['read.report_package']), $tenantA)
            ->getJson('/api/package/report/aging')
            ->assertOk()
            ->assertJsonPath('total_pending', 3);
    }

    // =========================================================================
    // Aging — permission gate (5.3.1 item 3)
    // =========================================================================

    public function test_aging_rejects_user_without_report_package_permission(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $security = $this->makeRoleUser($tenant, 'security');

        // security role has read.package and create.package, but NOT read.report_package
        $this->tenantRequest($this->token($security, ['read.package']), $tenant)
            ->getJson('/api/package/report/aging')
            ->assertForbidden();
    }

    // =========================================================================
    // Export — content-type and filename (5.3.1 item 4)
    // =========================================================================

    public function test_export_returns_xlsx_with_correct_headers(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(2)]);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $response = $this->tenantRequest($this->token($admin, ['read.report_package']), $tenant)
            ->get('/api/package/report/aging/export');

        $response->assertOk();

        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type', ''),
            'Content-Type must be xlsx'
        );

        $this->assertStringContainsString(
            'paquetes-pendientes-',
            $response->headers->get('Content-Disposition', ''),
            'Content-Disposition must contain the expected filename prefix'
        );
    }

    // =========================================================================
    // Export — only ready packages exported (5.3.1 item 5)
    // =========================================================================

    public function test_export_includes_only_ready_packages(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        // 2 READY packages
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(1)]);
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(5)]);

        // 3 PICKED_UP packages — must NOT appear in the export
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(2),
            'package_status_id' => PackageStatus::PICKED_UP,
        ]);
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(3),
            'package_status_id' => PackageStatus::PICKED_UP,
        ]);
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(4),
            'package_status_id' => PackageStatus::PICKED_UP,
        ]);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $response = $this->tenantRequest($this->token($admin, ['read.report_package']), $tenant)
            ->get('/api/package/report/aging/export');

        $response->assertOk();

        // BinaryFileResponse streams content from a temp file on disk.
        // Access the underlying file path directly to load it with PhpSpreadsheet.
        // ReportExport uses 2 header rows (title row + column-names row); data starts at row 3.
        $filePath = $response->baseResponse->getFile()->getPathname();

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        // Subtract title row (row 1) + headers row (row 2).
        $dataRows = $highestRow - 2;

        $this->assertSame(
            2,
            $dataRows,
            'Export must contain exactly 2 data rows (2 READY + 0 of the 3 PICKED_UP)'
        );
    }

    // =========================================================================
    // Export — formula-leading free-text cells are de-fanged (plan 059)
    // =========================================================================

    public function test_export_de_fangs_formula_leading_free_text_fields(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(2),
            'description' => '=HYPERLINK("http://evil.test","click me")',
            'tracking_number' => '+1234567890',
            'carrier_other' => '@SUM(A1:A10)',
            'storage_location' => 'Anaquel 3', // control: no dangerous prefix
        ]);

        $admin = $this->makeRoleUser($tenant, 'administrator');

        $response = $this->tenantRequest($this->token($admin, ['read.report_package']), $tenant)
            ->get('/api/package/report/aging/export');

        $response->assertOk();

        $filePath = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        // Column order per PackageReportController::reportColumns():
        // A=Unidad, B=Transportista, C=Número de seguimiento, D=Descripción,
        // E=Ubicación de almacenamiento. Data starts at row 3 (title + headers row).
        $carrierCell = $sheet->getCell('B3');
        $trackingCell = $sheet->getCell('C3');
        $descriptionCell = $sheet->getCell('D3');
        $storageCell = $sheet->getCell('E3');

        $this->assertSame("'@SUM(A1:A10)", $carrierCell->getValue());
        $this->assertSame("'+1234567890", $trackingCell->getValue());
        $this->assertSame('\'=HYPERLINK("http://evil.test","click me")', $descriptionCell->getValue());
        $this->assertSame('Anaquel 3', $storageCell->getValue(), 'Values without a dangerous prefix must NOT be altered');

        // The property that actually matters: the cell is written as a STRING,
        // never as a FORMULA, so no spreadsheet client will ever evaluate it —
        // regardless of how the leading apostrophe is rendered.
        $this->assertNotSame(DataType::TYPE_FORMULA, $descriptionCell->getDataType());
    }

    // =========================================================================
    // pendingCount — correct totals (5.3.1 item 6)
    // Guard (create.package) is used — see permission-gate tests below for why.
    // =========================================================================

    public function test_pending_count_returns_total_and_d7_plus(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        // 2 packages at 9 days old → d7_plus
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(9)]);
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(9)]);

        // 3 packages at 1 day old → NOT in d7_plus
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(1)]);
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(1)]);
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(1)]);

        // Guards hold create.package — they are allowed to call this widget.
        $guard = $this->makeRoleUser($tenant, 'security');

        $this->tenantRequest($this->token($guard, ['create.package']), $tenant)
            ->getJson('/api/package/report/pending-count')
            ->assertOk()
            ->assertJsonPath('total', 5)
            ->assertJsonPath('d7_plus', 2);
    }

    // =========================================================================
    // pendingCount — zero case (5.3.1 item 7)
    // =========================================================================

    public function test_pending_count_returns_zero_when_no_ready_packages(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        // Create one PICKED_UP package — must not count as pending.
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(1),
            'package_status_id' => PackageStatus::PICKED_UP,
        ]);

        // Guards hold create.package — they are allowed to call this widget.
        $guard = $this->makeRoleUser($tenant, 'security');

        $this->tenantRequest($this->token($guard, ['create.package']), $tenant)
            ->getJson('/api/package/report/pending-count')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('d7_plus', 0);
    }

    // =========================================================================
    // pendingCount — only READY counted; INCIDENT/RETURNED/PICKED_UP excluded
    // =========================================================================

    public function test_pending_count_counts_only_ready_packages_excluding_other_statuses(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);

        // 2 READY packages: both must be in total; only the old one in d7_plus.
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(10)]);
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(2)]);

        // 1 INCIDENT — same "in portería" physical state but must NOT be counted.
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(3),
            'package_status_id' => PackageStatus::INCIDENT,
        ]);

        // 1 RETURNED — already left the building; must NOT be counted.
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(4),
            'package_status_id' => PackageStatus::RETURNED,
        ]);

        // 1 PICKED_UP — resident already collected it; must NOT be counted.
        $this->createPackage($tenant, $unit, [
            'received_at' => now()->subDays(5),
            'package_status_id' => PackageStatus::PICKED_UP,
        ]);

        // Guards hold create.package — they are allowed to call this widget.
        $guard = $this->makeRoleUser($tenant, 'security');

        $this->tenantRequest($this->token($guard, ['create.package']), $tenant)
            ->getJson('/api/package/report/pending-count')
            ->assertOk()
            ->assertJsonPath('total', 2)    // only the 2 READY packages
            ->assertJsonPath('d7_plus', 1); // only the 10-day-old READY package
    }

    // =========================================================================
    // pendingCount — tenant isolation
    // =========================================================================

    public function test_pending_count_is_tenant_scoped(): void
    {
        $tenantA = $this->makeTenant('Cluster A');
        $tenantB = $this->makeTenant('Cluster B');
        $this->seedTenant($tenantA);
        $this->seedTenant($tenantB);

        $unitA = $this->makeUnit($tenantA);
        $unitB = $this->makeUnit($tenantB);

        // Tenant A: 3 READY packages (one old enough for d7_plus).
        $this->createPackage($tenantA, $unitA, ['received_at' => now()->subDays(1)]);
        $this->createPackage($tenantA, $unitA, ['received_at' => now()->subDays(2)]);
        $this->createPackage($tenantA, $unitA, ['received_at' => now()->subDays(10)]);

        // Tenant B: 5 READY packages — must not bleed into tenant A's count.
        for ($i = 0; $i < 5; $i++) {
            $this->createPackage($tenantB, $unitB, ['received_at' => now()->subDays(1)]);
        }

        $adminA = $this->makeRoleUser($tenantA, 'administrator');

        $this->tenantRequest($this->token($adminA, ['read.package', 'create.package']), $tenantA)
            ->getJson('/api/package/report/pending-count')
            ->assertOk()
            ->assertJsonPath('total', 3)    // only tenant A's 3 READY packages
            ->assertJsonPath('d7_plus', 1); // only tenant A's 10-day-old READY package
    }

    // =========================================================================
    // pendingCount — permission gate (FIX 2)
    // Residents get 403; guards (create.package) and board (read.report_package) get 200.
    // =========================================================================

    public function test_pending_count_forbids_resident(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $resident = $this->makeRoleUser($tenant, 'resident');

        // Resident only holds read.package — not enough to call the cluster-wide widget.
        $this->tenantRequest($this->token($resident, ['read.package']), $tenant)
            ->getJson('/api/package/report/pending-count')
            ->assertForbidden();
    }

    public function test_pending_count_allows_guard_with_create_package(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(1)]);

        $guard = $this->makeRoleUser($tenant, 'security');

        $this->tenantRequest($this->token($guard, ['create.package']), $tenant)
            ->getJson('/api/package/report/pending-count')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    public function test_pending_count_allows_board_with_read_report_package(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $this->createPackage($tenant, $unit, ['received_at' => now()->subDays(1)]);

        // President/treasurer/oversight_committee hold read.report_package.
        $board = $this->makeRoleUser($tenant, 'treasurer');

        $this->tenantRequest($this->token($board, ['read.report_package']), $tenant)
            ->getJson('/api/package/report/pending-count')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    // =========================================================================
    // reportColumns — at least 8 definitions (5.3.1 bonus)
    // =========================================================================

    public function test_report_columns_returns_at_least_eight_definitions(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $controller = app(PackageReportController::class);
        $columns = $controller->reportColumns();

        $this->assertGreaterThanOrEqual(8, count($columns), 'reportColumns must return at least 8 column definitions');

        foreach ($columns as $label => $callable) {
            $this->assertIsString($label, 'Column key must be a Spanish string');
            $this->assertIsCallable($callable, "Column '{$label}' must be callable");
        }
    }
}
