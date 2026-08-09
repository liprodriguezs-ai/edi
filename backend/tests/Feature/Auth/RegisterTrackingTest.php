<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tracking\DTO\TrackingEvent;
use Modules\Tracking\Services\TrackingDispatcher;
use QCH\Core\Models\Role;
use RuntimeException;
use Tests\TestCase;

/**
 * Coverage for the CompleteRegistration tracking hook added to
 * AuthController::register(). The browser (useSession.register) already fires
 * BOTH CompleteRegistration and StartTrial to gtag/fbq/POST-track sharing ONE
 * event_id, so this server hook is Facebook-ONLY (adblocker-resilient CAPI
 * backup, deduped via the SAME event_id) — no GA4 (would double-count against
 * gtag) and no server-side StartTrial (no browser dedup key to reuse). The
 * hook is best-effort — it must never break account creation.
 */
final class RegisterTrackingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private function seedAccountOwnerRole(): void
    {
        \App\Models\Role::firstOrCreate(['name' => Role::SLUG_ACCOUNT_OWNER, 'guard_name' => 'api']);
    }

    public function test_register_dispatches_complete_registration_to_facebook_only_with_the_client_event_id(): void
    {
        $this->seedAccountOwnerRole();

        $fakeDispatcher = $this->createMock(TrackingDispatcher::class);
        $fakeDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->callback(fn (TrackingEvent $event): bool => $event->name === 'CompleteRegistration'
                    && $event->eventId === '33333333-3333-3333-3333-333333333333'
                    && $event->actionSource === 'system_generated'),
                ['facebook'],
            );
        $this->app->instance(TrackingDispatcher::class, $fakeDispatcher);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ana',
            'cluster_name' => 'Condominio Ana',
            'email' => 'ana.tracking@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'event_id' => '33333333-3333-3333-3333-333333333333',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user', ['email' => 'ana.tracking@example.com']);
    }

    public function test_register_dispatches_only_once_no_start_trial_no_google_analytics(): void
    {
        $this->seedAccountOwnerRole();

        $fakeDispatcher = $this->createMock(TrackingDispatcher::class);
        // Exactly ONE dispatch call total: no StartTrial, no google_analytics.
        $fakeDispatcher->expects($this->once())->method('dispatch');
        $this->app->instance(TrackingDispatcher::class, $fakeDispatcher);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Caro',
            'cluster_name' => 'Condominio Caro',
            'email' => 'caro.tracking@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(200);
    }

    public function test_register_still_succeeds_when_tracking_dispatch_throws(): void
    {
        $this->seedAccountOwnerRole();

        $throwingDispatcher = $this->createMock(TrackingDispatcher::class);
        $throwingDispatcher->method('dispatch')->willThrowException(new RuntimeException('tracking down'));
        $this->app->instance(TrackingDispatcher::class, $throwingDispatcher);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Beto',
            'cluster_name' => 'Condominio Beto',
            'email' => 'beto.tracking@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user', ['email' => 'beto.tracking@example.com']);
    }
}
