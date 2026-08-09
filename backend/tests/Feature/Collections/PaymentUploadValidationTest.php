<?php

declare(strict_types=1);

namespace Tests\Feature\Collections;

use App\Models\User;
use Database\Seeders\ResetPermissionTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Collections\Models\PaymentMethod;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Payment upload validation: the `receipt` upload must reject non-document MIME
 * types (a disguised .php/.svg/.html is a stored-XSS / execution vector), and
 * `notes` must be length-capped.
 */
final class PaymentUploadValidationTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_pdf_receipt_is_accepted(): void
    {
        [$tenant, $token, $unit, $bank, $transfer] = $this->scenario();

        $response = $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
                'reference' => 'TRX1',
                'receipt' => $this->fakeFile('comprobante.pdf', 'application/pdf'),
            ]);

        $this->assertNotSame(422, $response->status(), 'A valid PDF receipt must be accepted');
    }

    public function test_php_receipt_is_rejected(): void
    {
        [$tenant, $token, $unit, $bank, $transfer] = $this->scenario();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
                'reference' => 'TRX2',
                'receipt' => $this->fakeFile('shell.php', 'application/x-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['receipt']);
    }

    public function test_notes_exceeding_max_is_rejected(): void
    {
        [$tenant, $token, $unit, $bank, $transfer] = $this->scenario();

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/payment/submit', [
                'unit_id' => $unit->id,
                'paid_at' => '2026-03-14',
                'total_amount' => 50000,
                'payment_method_id' => $transfer,
                'bank_account_id' => $bank->id,
                'reference' => 'TRX3',
                'notes' => str_repeat('a', 2001),
                'receipt' => $this->fakeFile('comprobante.pdf', 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    // =========================================================================
    // Scenario + helpers
    // =========================================================================

    /**
     * @return array{0: Tenant, 1: string, 2: Unit, 3: BankAccount, 4: string}
     */
    private function scenario(): array
    {
        $this->seedRolePermissions();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $unit = $this->makeUnit($tenant);
        $resident = $this->makeUser($tenant);
        $this->attachResident($tenant, $unit, $resident);
        $bank = $this->makeBankAccount($tenant);
        $transfer = $this->inTenant($tenant, fn () => PaymentMethod::TRANSFER);
        $token = $resident->createToken('test', ['create.payment_submission'])->plainTextToken;

        return [$tenant, $token, $unit, $bank, $transfer];
    }

    private function seedRolePermissions(): void
    {
        Artisan::call('db:seed', [
            '--class' => ResetPermissionTableSeeder::class,
            '--force' => true,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

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
                'ownership_share' => 1.0,
            ]);
        });

        return $unit;
    }

    private function attachResident(Tenant $tenant, Unit $unit, User $user): void
    {
        $tenant->run(function () use ($unit, $user): void {
            $relationshipId = UnitRelationship::query()->value('id');
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => $relationshipId,
                'is_billing_owner' => true,
                'start_date' => '2024-01-01',
            ]);
        });
    }

    private function makeBankAccount(Tenant $tenant): BankAccount
    {
        $account = null;
        $tenant->run(function () use (&$account): void {
            $type = BankAccountType::findOrFail(BankAccountType::CASH);
            $purpose = BankAccountPurpose::findOrFail(Catalog::id(BankAccountPurpose::class, 'Operativa'));
            $account = BankAccount::create([
                'name' => 'Caja efectivo',
                'bank_account_type_id' => $type->id,
                'bank_account_purpose_id' => $purpose->id,
                'currency' => 'MXN',
                'initial_balance' => 0,
                'current_balance' => 0,
                'is_primary' => true,
                'is_active' => true,
            ]);
        });

        return $account;
    }

    private function fakeFile(string $name, string $mime): UploadedFile
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vecinia_up_');
        $this->tempFiles[] = $tmpPath;
        // The `mimes:` rule sniffs file content, so a valid PDF needs real magic
        // bytes; the rejected types use plain script content (sniffs as text/php).
        $content = str_starts_with($mime, 'application/pdf')
            ? str_repeat('%PDF-1.4 comprobante ', 200)
            : "<?php echo 'malicious'; ?>".str_repeat('x', 256);
        file_put_contents($tmpPath, $content);

        return new UploadedFile($tmpPath, $name, $mime, null, true);
    }
}
