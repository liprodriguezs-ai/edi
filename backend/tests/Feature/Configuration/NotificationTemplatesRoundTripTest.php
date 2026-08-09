<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * `setting-value-native-json` task T4.4 — `getNotificationTemplates()`
 * payload parity across the cutover (design "Notification templates payload
 * unchanged"). Uses a wildcard-scoped bearer token (NOT the session `login()`
 * helper `ClusterConfigurationFeaturesTest` relies on) so this coverage does
 * not depend on the unrelated parallel billing-session WIP currently
 * breaking that login flow (401s unrelated to this change — verified via
 * `git status` showing AuthController.php/User.php/EnsureSubscriptionActive.php
 * as pre-existing dirty files this change never touched).
 */
final class NotificationTemplatesRoundTripTest extends TestCase
{
    use RefreshDatabase;
    use WithTenantSetup;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_get_reads_seeded_defaults_through_the_cast_not_a_raw_bypass(): void
    {
        $tenant = $this->makeTenant('Notification Templates Get Cluster');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $response = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/notification-templates')
            ->assertOk();

        $charged = collect($response->json('data'))->firstWhere('slug', 'notification_template.charge_created');

        $this->assertNotNull($charged);
        $this->assertIsString($charged['title']);
        $this->assertIsString($charged['body']);
        $this->assertTrue($charged['is_active']);
    }

    public function test_put_then_get_round_trips_the_same_payload_shape(): void
    {
        $tenant = $this->makeTenant('Notification Templates Round Trip Cluster');
        $this->seedTenant($tenant);
        $user = $this->makeUser($tenant);
        $token = $user->createToken('test', ['*'])->plainTextToken;

        $this->tenantRequest($token, $tenant)
            ->putJson('/api/config/notification-templates', [
                'templates' => [
                    [
                        'slug' => 'notification_template.charge_overdue',
                        'title' => 'Vencido!',
                        'body' => 'Hola {resident_name}, debes {amount}.',
                        'is_active' => false,
                    ],
                ],
            ])
            ->assertOk();

        $get = $this->tenantRequest($token, $tenant)
            ->getJson('/api/config/notification-templates')
            ->assertOk();

        $overdue = collect($get->json('data'))->firstWhere('slug', 'notification_template.charge_overdue');

        $this->assertSame('Vencido!', $overdue['title']);
        $this->assertSame('Hola {resident_name}, debes {amount}.', $overdue['body']);
        $this->assertFalse($overdue['is_active']);
    }
}
