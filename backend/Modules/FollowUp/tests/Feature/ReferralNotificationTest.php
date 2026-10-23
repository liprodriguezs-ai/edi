<?php

declare(strict_types=1);

namespace Modules\FollowUp\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FollowUp\Models\FollowUp;
use Modules\FollowUp\Models\FollowUpType;
use Modules\FollowUp\Models\ReferralSpecialty;
use Modules\FollowUp\Tests\Support\WithFollowUpFixtures;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationEvent;
use Modules\Notification\Models\NotificationSetting;
use Modules\Notification\Models\NotificationUser;
use Tests\TestCase;

/**
 * REQ-FUP-008. Disables the RefreshDatabase transaction wrapper (same trick
 * as tests/Feature/Notifications/NotificationDeliveryTest.php) so
 * notifyEvent()'s DB::afterCommit callback fires within the test instead of
 * being deferred past it.
 */
final class ReferralNotificationTest extends TestCase
{
    use RefreshDatabase;
    use WithFollowUpFixtures;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function referralFollowUpFixture(string $token, $tenant): array
    {
        $version = $this->defaultVersion();
        $fx = $this->inTenant($tenant, fn () => $this->makeChildVisit(570));
        $screeningId = $this->applyAndComplete($token, $tenant, $fx['visit'], $this->g10RedAnswers($version));

        [$followUpId, $specialtyId] = $this->inTenant($tenant, fn () => [
            FollowUp::where('screening_id', $screeningId)->where('follow_up_type_id', FollowUpType::SPECIALIST_REFERRAL)->firstOrFail()->id,
            ReferralSpecialty::firstOrFail()->id,
        ]);

        return [$followUpId, $specialtyId];
    }

    public function test_dispatched_with_defaults_to_administrators(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);
        $adminId = $this->inTenant($tenant, fn () => User::whereHas('roles', fn ($q) => $q->where('name', 'administrator'))->value('id'));

        [$followUpId, $specialtyId] = $this->referralFollowUpFixture($token, $tenant);

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/referral', [
                'follow_up_id' => $followUpId,
                'referral_specialty_id' => $specialtyId,
                'referred_at' => now()->toDateString(),
            ])
            ->assertCreated();

        $this->inTenant($tenant, function () use ($adminId): void {
            $recorded = NotificationUser::where('user_id', $adminId)
                ->whereHas('notification', fn ($q) => $q->where('title', 'Nueva referencia a especialista'))
                ->exists();

            $this->assertTrue($recorded, 'Expected an in-app notification row for the administrator.');
        });
    }

    public function test_tenant_opt_out_suppresses_the_notification(): void
    {
        $this->seedPlatformRegistry();
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);
        $token = $this->administratorToken($tenant);

        $this->inTenant($tenant, function (): void {
            // TenantNotificationSettingSeeder already seeded a row for this
            // event when the tenant was created, so opt out via
            // updateOrCreate (not create(), which would violate the
            // (tenant_id, notification_event_id) unique index).
            NotificationSetting::updateOrCreate(
                ['notification_event_id' => NotificationEvent::REFERRAL_CREATED],
                [
                    'is_active' => false,
                    'channel_email' => false,
                    'channel_push' => false,
                    'channel_whatsapp' => false,
                ],
            );
        });

        [$followUpId, $specialtyId] = $this->referralFollowUpFixture($token, $tenant);

        $before = $this->inTenant($tenant, fn () => Notification::where('title', 'Nueva referencia a especialista')->count());

        $this->tenantRequest($token, $tenant)
            ->postJson('/api/referral', [
                'follow_up_id' => $followUpId,
                'referral_specialty_id' => $specialtyId,
                'referred_at' => now()->toDateString(),
            ])
            ->assertCreated();

        $after = $this->inTenant($tenant, fn () => Notification::where('title', 'Nueva referencia a especialista')->count());

        $this->assertSame($before, $after, 'Expected zero notifications recorded after opting out.');
    }

    public function test_event_key_resolves_to_the_registered_catalog_row(): void
    {
        $this->seedPlatformRegistry();

        $event = NotificationEvent::findByKey('follow_up.referral_created');

        $this->assertNotNull($event);
        $this->assertSame(NotificationEvent::REFERRAL_CREATED, $event->id);
        $this->assertSame(['log', 'firebase_push'], $event->default_channels);
        $this->assertFalse($event->supports_email);
    }
}
