<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Part A: Regression tests for the Tenant operational columns
 * (timezone, currency, type, uses_ownership_share).
 *
 * Verifies that these are REAL columns (persisted to DB) and
 * do NOT fall into the Stancl VirtualColumn `data` JSON blob.
 */
final class TenantOperationalColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private function makeTenant(array $attributes = []): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create(array_merge([
                'id' => (string) Str::uuid(),
                'name' => 'Test Tenant',
                'status' => Tenant::STATUS_ACTIVO,
            ], $attributes)),
            [TenantCreated::class],
        );
    }

    public function test_timezone_is_persisted_as_real_column_not_in_data_json(): void
    {
        $tenant = $this->makeTenant(['timezone' => 'America/Monterrey']);

        $fresh = Tenant::find($tenant->id);

        $this->assertSame('America/Monterrey', $fresh->timezone);

        // Must NOT be in the data JSON blob.
        $dataJson = DB::table('tenant')->where('id', $tenant->id)->value('data');
        $data = json_decode($dataJson ?? '{}', true) ?? [];
        $this->assertArrayNotHasKey('timezone', $data, 'timezone must be a real column, not stored in data JSON.');
    }

    public function test_currency_is_persisted_as_real_column_not_in_data_json(): void
    {
        $tenant = $this->makeTenant(['currency' => 'USD']);

        $fresh = Tenant::find($tenant->id);

        $this->assertSame('USD', $fresh->currency);

        $dataJson = DB::table('tenant')->where('id', $tenant->id)->value('data');
        $data = json_decode($dataJson ?? '{}', true) ?? [];
        $this->assertArrayNotHasKey('currency', $data, 'currency must be a real column, not stored in data JSON.');
    }

    public function test_type_is_persisted_as_real_column_not_in_data_json(): void
    {
        $tenant = $this->makeTenant(['type' => 'vertical']);

        $fresh = Tenant::find($tenant->id);

        $this->assertSame('vertical', $fresh->type);

        $dataJson = DB::table('tenant')->where('id', $tenant->id)->value('data');
        $data = json_decode($dataJson ?? '{}', true) ?? [];
        $this->assertArrayNotHasKey('type', $data, 'type must be a real column, not stored in data JSON.');
    }

    public function test_uses_ownership_share_is_persisted_as_real_column_not_in_data_json(): void
    {
        $tenant = $this->makeTenant(['uses_ownership_share' => true]);

        $fresh = Tenant::find($tenant->id);

        $this->assertTrue((bool) $fresh->uses_ownership_share);

        $dataJson = DB::table('tenant')->where('id', $tenant->id)->value('data');
        $data = json_decode($dataJson ?? '{}', true) ?? [];
        $this->assertArrayNotHasKey('uses_ownership_share', $data, 'uses_ownership_share must be a real column, not stored in data JSON.');
    }

    public function test_default_values_are_applied_correctly(): void
    {
        $tenant = $this->makeTenant();

        $fresh = Tenant::find($tenant->id);

        $this->assertSame('America/Mexico_City', $fresh->timezone, 'Default timezone should be America/Mexico_City.');
        $this->assertSame('MXN', $fresh->currency, 'Default currency should be MXN.');
        $this->assertNull($fresh->type, 'Default type should be null.');
        $this->assertFalse((bool) $fresh->uses_ownership_share, 'Default uses_ownership_share should be false.');
    }

    public function test_all_four_columns_round_trip(): void
    {
        $tenant = $this->makeTenant([
            'timezone' => 'America/New_York',
            'currency' => 'USD',
            'type' => 'mixed',
            'uses_ownership_share' => true,
        ]);

        $fresh = Tenant::find($tenant->id);

        $this->assertSame('America/New_York', $fresh->timezone);
        $this->assertSame('USD', $fresh->currency);
        $this->assertSame('mixed', $fresh->type);
        $this->assertTrue((bool) $fresh->uses_ownership_share);
    }

    public function test_get_custom_columns_includes_all_four_new_columns(): void
    {
        $columns = Tenant::getCustomColumns();

        $this->assertContains('timezone', $columns);
        $this->assertContains('currency', $columns);
        $this->assertContains('type', $columns);
        $this->assertContains('uses_ownership_share', $columns);
    }
}
