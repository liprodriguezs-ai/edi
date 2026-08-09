<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\TestCase;

/**
 * Notification center per-user scoping (§17.2 — regression for notifications-07):
 * a tenant member must only be able to list/read/mark/delete/restore THEIR OWN
 * notification_user rows; any foreign id resolves to 404. GET /api/notification is
 * limited to the notifications the user actually received unless the token carries
 * the real read.notification ability.
 *
 * Also covers the legacy broadcast pipeline hardening (notifications-15):
 * POST /api/notification validates title/message and ignores client-chosen ids.
 */
final class NotificationCenterScopingTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private Tenant $tenant;

    private User $userA;

    private User $userB;

    private NotificationUser $rowA;

    private NotificationUser $rowB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'Center Scoping Cluster',
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        $this->userA = $this->makeMember('User A');
        $this->userB = $this->makeMember('User B');

        $this->tenant->run(function (): void {
            $notificationA = Notification::create([
                'title' => 'Pago confirmado',
                'message' => 'Se registró tu pago de $700 (folio R-2026-000123).',
                'status' => 'sent',
            ]);
            $notificationB = Notification::create([
                'title' => 'Cuota por vencer',
                'message' => 'Tu cuota de marzo vence en 3 días.',
                'status' => 'sent',
            ]);

            $this->rowA = NotificationUser::create([
                'notification_id' => $notificationA->id,
                'user_id' => $this->userA->id,
                'is_read' => false,
            ]);
            $this->rowB = NotificationUser::create([
                'notification_id' => $notificationB->id,
                'user_id' => $this->userB->id,
                'is_read' => false,
            ]);
        });
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    private function makeMember(string $name): User
    {
        $user = User::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($this->tenant->id);

        return $user;
    }

    /**
     * Build a tenant-scoped, token-authenticated request. Flushing the resolved
     * guards forces each request to re-resolve its own bearer token.
     *
     * @param  array<int, string>  $abilities
     */
    private function actingRequest(User $user, array $abilities = ['*']): self
    {
        $this->app['auth']->forgetGuards();
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $token = $user->createToken('test', $abilities)->plainTextToken;

        $this->withToken($token)->withHeader('X-Tenant-Id', $this->tenant->id);

        return $this;
    }

    // =========================================================================
    // notification_user — per-user scoping
    // =========================================================================

    public function test_index_returns_only_own_rows(): void
    {
        $response = $this->actingRequest($this->userA)
            ->getJson('/api/notification_user')
            ->assertStatus(200);

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame([$this->rowA->id], $ids);
    }

    public function test_show_of_foreign_row_returns_404(): void
    {
        $this->actingRequest($this->userA)
            ->getJson('/api/notification_user/'.$this->rowB->id)
            ->assertStatus(404);
    }

    public function test_update_of_foreign_row_returns_404_and_does_not_mutate(): void
    {
        $this->actingRequest($this->userA)
            ->patchJson('/api/notification_user/'.$this->rowB->id, ['is_read' => true])
            ->assertStatus(404);

        $this->assertDatabaseHas('notification_user', [
            'id' => $this->rowB->id,
            'is_read' => false,
        ]);
    }

    public function test_destroy_and_restore_of_foreign_row_return_404(): void
    {
        $this->actingRequest($this->userA)
            ->deleteJson('/api/notification_user/'.$this->rowB->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('notification_user', [
            'id' => $this->rowB->id,
            'deleted_at' => null,
        ]);

        $this->actingRequest($this->userA)
            ->postJson('/api/notification_user/'.$this->rowB->id.'/restore')
            ->assertStatus(404);
    }

    public function test_search_filtered_by_foreign_user_id_leaks_nothing(): void
    {
        $response = $this->actingRequest($this->userA)
            ->postJson('/api/notification_user/search', [
                'filters' => [
                    ['field' => 'user_id', 'operator' => '=', 'value' => $this->userB->id],
                ],
            ])
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    public function test_batch_update_cannot_touch_foreign_rows(): void
    {
        $this->actingRequest($this->userA)
            ->patchJson('/api/notification_user/batch', [
                'resources' => [
                    $this->rowB->id => ['is_read' => true],
                ],
            ]);

        $this->assertDatabaseHas('notification_user', [
            'id' => $this->rowB->id,
            'is_read' => false,
        ]);
    }

    public function test_owner_can_mark_own_notification_as_read(): void
    {
        $this->actingRequest($this->userA)
            ->patchJson('/api/notification_user/'.$this->rowA->id, ['is_read' => true])
            ->assertStatus(200);

        $this->assertDatabaseHas('notification_user', [
            'id' => $this->rowA->id,
            'is_read' => true,
        ]);
    }

    public function test_store_forces_authenticated_user_as_owner(): void
    {
        $notificationId = null;
        $this->tenant->run(function () use (&$notificationId): void {
            $notificationId = Notification::create([
                'title' => 'Aviso',
                'message' => 'Contenido',
                'status' => 'sent',
            ])->id;
        });

        $response = $this->actingRequest($this->userA)
            ->postJson('/api/notification_user', [
                'notification_id' => $notificationId,
                'user_id' => $this->userB->id,
                'is_read' => false,
            ])
            ->assertStatus(201);

        $this->assertSame(
            $this->userA->id,
            $response->json('data.user_id'),
            'A member must never create center rows on behalf of another user',
        );
    }

    // =========================================================================
    // notification — broadcast content limited to what the user received
    // =========================================================================

    public function test_notification_index_is_limited_to_received_for_regular_members(): void
    {
        $response = $this->actingRequest($this->userA, ['read.notification_user'])
            ->getJson('/api/notification')
            ->assertStatus(200);

        $titles = array_column($response->json('data'), 'title');

        $this->assertContains('Pago confirmado', $titles);
        $this->assertNotContains(
            'Cuota por vencer',
            $titles,
            'A member must not read broadcast rows that were never addressed to them',
        );
    }

    public function test_notification_index_is_unrestricted_with_real_read_ability(): void
    {
        $response = $this->actingRequest($this->userA, ['*'])
            ->getJson('/api/notification')
            ->assertStatus(200);

        $titles = array_column($response->json('data'), 'title');

        $this->assertContains('Pago confirmado', $titles);
        $this->assertContains('Cuota por vencer', $titles);
    }

    // =========================================================================
    // notifications-15 — legacy broadcast pipeline hardening
    // =========================================================================

    public function test_store_notification_requires_title_and_message(): void
    {
        $this->actingRequest($this->userA, ['*'])
            ->postJson('/api/notification', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'message']);
    }

    public function test_store_notification_ignores_client_supplied_primary_key(): void
    {
        $chosenId = (string) Str::uuid();

        $response = $this->actingRequest($this->userA, ['*'])
            ->postJson('/api/notification', [
                'id' => $chosenId,
                'title' => 'Broadcast',
                'message' => 'Contenido del aviso',
            ])
            ->assertStatus(201);

        $this->assertNotSame($chosenId, $response->json('data.id'));
        $this->assertDatabaseMissing('notification', ['id' => $chosenId]);
    }

    public function test_store_notification_requires_create_ability(): void
    {
        $this->actingRequest($this->userA, ['read.notification_user'])
            ->postJson('/api/notification', [
                'title' => 'Broadcast',
                'message' => 'Contenido del aviso',
            ])
            ->assertStatus(403);
    }
}
