<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Chat\Models\ChatChannel;
use Modules\Chat\Models\ChatChannelType;
use Modules\Chat\Services\ChatService;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitRelationship;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Tests\Support\Catalog;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Fase 3 — channel post notifications target the channel AUDIENCE resolved by the
 * unit-based target system (TargetResolver → units → active residents), exactly
 * like announcements — NOT chat_channel_member rows. They honor notify_on_post and
 * per-user notif_pref. (The notification ENGINE batching is covered separately.)
 */
final class ChatNotificationTest extends TestCase
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

        parent::tearDown();
    }

    private function makeUser(Tenant $tenant, string $name = 'Vecino'): User
    {
        $user = User::create([
            'name' => $name.' '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    /** Give the user an active unit so they count as a resident of the 'all' audience. */
    private function makeResident(Tenant $tenant, User $user, Tower $tower): void
    {
        $tenant->run(static function () use ($user, $tower): void {
            $unit = Unit::create([
                'unit_type_id' => Catalog::id(UnitType::class, 'Departamento'),
                'number' => (string) mt_rand(1, 99999),
                'tower_id' => $tower->id,
                'floor' => '1',
                'unit_status_id' => Catalog::id(UnitStatus::class, 'Activa'),
            ]);

            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => UnitRelationship::OWNER,
                'is_billing_owner' => true,
                'start_date' => now()->toDateString(),
            ]);
        });
    }

    private function makeTower(Tenant $tenant, string $name): Tower
    {
        return $tenant->run(static fn (): Tower => Tower::create(['name' => $name, 'floors_count' => 3]));
    }

    private function makeAllChannel(bool $notifyOnPost = true): ChatChannel
    {
        return ChatChannel::create([
            'name' => 'Canal '.Str::random(5),
            'chat_channel_type_id' => ChatChannelType::CUSTOM,
            'target_type' => 'all',
            'target_selector' => null,
            'is_moderated' => false,
            'is_system' => false,
            'is_archived' => false,
            'notify_on_post' => $notifyOnPost,
        ]);
    }

    private function wasNotified(string $userId): bool
    {
        return NotificationUser::where('user_id', $userId)
            ->whereHas('notification', fn ($q) => $q->where('title', 'like', 'Nuevo mensaje%'))
            ->exists();
    }

    public function test_post_notifies_full_audience_even_without_membership_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');
        $listener = $this->makeUser($tenant, 'Oyente');
        $tower = $this->makeTower($tenant, 'Torre Única');
        $this->makeResident($tenant, $author, $tower);
        $this->makeResident($tenant, $listener, $tower);

        $tenant->run(function () use ($author, $listener): void {
            // 'all' audience = active residents. The listener NEVER joined (no membership row).
            $channel = $this->makeAllChannel();

            app(ChatService::class)->postMessage($channel, $author->id, 'Hola audiencia');

            $this->assertTrue($this->wasNotified($listener->id), 'Resident audience is notified without a membership row');
            $this->assertFalse($this->wasNotified($author->id), 'Author is never notified of their own message');
        });
    }

    public function test_notify_on_post_false_suppresses_notifications(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');
        $listener = $this->makeUser($tenant, 'Oyente');
        $tower = $this->makeTower($tenant, 'Torre Única');
        $this->makeResident($tenant, $author, $tower);
        $this->makeResident($tenant, $listener, $tower);

        $tenant->run(function () use ($author, $listener): void {
            $channel = $this->makeAllChannel(notifyOnPost: false);

            app(ChatService::class)->postMessage($channel, $author->id, 'Sin notificar');

            $this->assertFalse($this->wasNotified($listener->id), 'notify_on_post=false must suppress all post notifications');
        });
    }

    public function test_notif_pref_none_excludes_recipient(): void
    {
        $tenant = $this->makeTenant();
        $this->seedTenant($tenant);

        $author = $this->makeUser($tenant, 'Autor');
        $optedOut = $this->makeUser($tenant, 'Silenciado');
        $optedIn = $this->makeUser($tenant, 'Activo');
        $tower = $this->makeTower($tenant, 'Torre Única');
        $this->makeResident($tenant, $author, $tower);
        $this->makeResident($tenant, $optedOut, $tower);
        $this->makeResident($tenant, $optedIn, $tower);

        $tenant->run(function () use ($author, $optedOut, $optedIn): void {
            $channel = $this->makeAllChannel();

            $service = app(ChatService::class);
            // Per-user state rows carry the notification preference.
            $service->addMember($channel, $optedOut->id, 'none');
            $service->addMember($channel, $optedIn->id, 'all');

            $service->postMessage($channel, $author->id, 'Hola con preferencias');

            $this->assertFalse($this->wasNotified($optedOut->id), "notif_pref 'none' must not be notified");
            $this->assertTrue($this->wasNotified($optedIn->id), "notif_pref 'all' must be notified");
        });
    }
}
