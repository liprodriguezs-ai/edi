<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Collections\Models\Charge;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Casts\TenantMoneyCast;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Verifies TenantMoneyCast/TenantMoney resolve currency per call from the
 * active tenancy — never a static default, never a hardcoded parameter.
 *
 * Uses Charge::setRawAttributes() to exercise the real cast wiring
 * (Charge::casts() -> TenantMoneyCast::class, no ':MXN' param) without
 * needing the model's full FK graph (charge_batch, unit, etc.) — hydration
 * this way mirrors what Eloquent does internally when reading a DB row.
 */
final class TenantMoneyCastTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_two_tenants_with_different_currency_serialize_the_same_minor_units_differently(): void
    {
        $tenantA = $this->makeTenant(['currency' => 'MXN']);
        $tenantB = $this->makeTenant(['currency' => 'USD']);

        $chargeA = new Charge;
        $chargeA->setRawAttributes(['expected_amount' => 150000]);

        $chargeB = new Charge;
        $chargeB->setRawAttributes(['expected_amount' => 150000]);

        tenancy()->initialize($tenantA);
        $serializedA = $chargeA->expected_amount->toArray();
        tenancy()->end();

        tenancy()->initialize($tenantB);
        $serializedB = $chargeB->expected_amount->toArray();
        tenancy()->end();

        $this->assertSame('MXN', $serializedA['currency']);
        $this->assertSame('USD', $serializedB['currency']);
        $this->assertSame('150000', $serializedA['amount']);
        $this->assertSame($serializedA['amount'], $serializedB['amount']);
    }

    public function test_central_context_without_tenancy_falls_back_to_config_default_currency(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $charge = new Charge;
        $charge->setRawAttributes(['expected_amount' => 150000]);

        $serialized = $charge->expected_amount->toArray();

        $this->assertSame(config('money.defaultCurrency', 'MXN'), $serialized['currency']);
        $this->assertSame('MXN', $serialized['currency']);
    }

    public function test_explicit_currency_parameter_passes_through_regardless_of_active_tenancy(): void
    {
        $tenant = $this->makeTenant(['currency' => 'MXN']);
        tenancy()->initialize($tenant);

        $cast = new TenantMoneyCast('USD');
        $money = $cast->get(new Charge, 'expected_amount', 150000, []);

        $this->assertSame('USD', $money->getCurrency()->getCode());

        tenancy()->end();
    }

    public function test_invalid_persisted_currency_falls_back_to_config_default(): void
    {
        // Design/tasks reference 'XXX_BAD' as the invalid value, but
        // tenant.currency is varchar(3) (confirmed via schema inspection) —
        // a 7-char string cannot be persisted at all. 'ZZZ' fits the column
        // and is confirmed NOT a valid ISO 4217 code via Money::isValidCurrency(),
        // exercising the exact same guard in TenantMoney::currency().
        $tenant = $this->makeTenant(['currency' => 'ZZZ']);

        $charge = new Charge;
        $charge->setRawAttributes(['expected_amount' => 150000]);

        tenancy()->initialize($tenant);
        $serialized = $charge->expected_amount->toArray();
        tenancy()->end();

        $this->assertSame('MXN', $serialized['currency']);
    }

    private function makeTenant(array $attributes = []): Tenant
    {
        return Event::fakeFor(
            static fn (): Tenant => Tenant::create(array_merge([
                'id' => (string) Str::uuid(),
                'name' => 'Money Test '.Str::random(5),
                'status' => Tenant::STATUS_ACTIVO,
            ], $attributes)),
            [TenantCreated::class],
        );
    }
}
