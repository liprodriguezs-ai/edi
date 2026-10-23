<?php

declare(strict_types=1);

namespace Modules\Appointment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Appointment\Tests\Support\WithAppointmentFixtures;
use Tests\TestCase;

/**
 * odd/tasks/edi-agenda.md S12 — Agenda is registered in the dynamic menu and its
 * catalogs resolve through /selects (Str::studly coupling with CatalogType).
 */
final class AppointmentMenuRegistrationTest extends TestCase
{
    use RefreshDatabase;
    use WithAppointmentFixtures;

    public function test_menu_shows_agenda_with_its_route_and_icon(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();

        $menu = $this->tenantRequest($token, $tenant)->getJson('/api/menu')->assertOk();

        $agenda = collect($menu->json('data'))
            ->flatMap(fn ($sys) => collect($sys['items'] ?? [])->flatMap(
                fn ($item) => ($item['type'] ?? null) === 'group' ? $item['modules'] : [$item]
            ))
            ->firstWhere('name', 'Agenda');

        $this->assertNotNull($agenda);
        $this->assertSame('/agenda', $agenda['route'] ?? null);
        $this->assertSame('IconCalendar', $agenda['icon'] ?? null);
    }

    public function test_selects_returns_the_agenda_catalogs(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->bootTenant();

        $response = $this->tenantRequest($token, $tenant)->postJson('/api/selects', [
            'appointment_type' => [],
            'appointment_status' => [],
        ])->assertOk();

        $data = $response->json('data') ?? $response->json();

        $this->assertCount(3, $data['appointment_type'] ?? []);
        $this->assertCount(6, $data['appointment_status'] ?? []);
    }
}
