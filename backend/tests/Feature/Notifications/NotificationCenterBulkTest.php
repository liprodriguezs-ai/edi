<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Database\QueryException;
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
 * Notification center bulk actions and data integrity (§17.2 — notifications-14/16):
 *
 * - POST /api/notification_user/mark-all-read → marks ONLY the caller's rows.
 *   (The unread badge is derived client-side from notification_user/search meta.total —
 *   there is no dedicated badge endpoint.)
 * - unique(notification_id, user_id) partial index: duplicates are rejected at the
 *   database level (the firstOrCreate race can no longer double the badge), while
 *   a soft-deleted row does NOT block redelivery of the same notification.
 */
final class NotificationCenterBulkTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string> */
    protected $connectionsToTransact = [];

    private Tenant $tenant;

    private User $userA;

    private User $userB;

    private Notification $notificationOne;

    private Notification $notificationTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Event::fakeFor(
            static fn (): Tenant => Tenant::create([
                'id' => (string) Str::uuid(),
                'name' => 'Bulk Center Organization',
                'status' => Tenant::STATUS_ACTIVO,
            ]),
            [TenantCreated::class],
        );

        $this->userA = $this->makeMember('Bulk A');
        $this->userB = $this->makeMember('Bulk B');

        $this->tenant->run(function (): void {
            $this->notificationOne = Notification::create([
                'title' => 'Pago confirmado',
                'message' => 'Se registró tu pago (folio R-2026-000321).',
                'status' => 'sent',
            ]);
            $this->notificationTwo = Notification::create([
                'title' => 'Cuota por vencer',
                'message' => 'Tu cuota vence en 3 días.',
                'status' => 'sent',
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

    private function makeCenterRow(Notification $notification, User $user, bool $isRead = false): NotificationUser
    {
        $row = null;
        $this->tenant->run(function () use (&$row, $notification, $user, $isRead): void {
            $row = NotificationUser::create([
                'notification_id' => $notification->id,
                'user_id' => $user->id,
                'is_read' => $isRead,
            ]);
        });

        return $row;
    }

    /**
     * Build a tenant-scoped, token-authenticated request.
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
    // notifications-16 — mark-all-read
    // =========================================================================

    public function test_mark_all_read_marks_only_the_callers_rows(): void
    {
        $ownOne = $this->makeCenterRow($this->notificationOne, $this->userA);
        $ownTwo = $this->makeCenterRow($this->notificationTwo, $this->userA);
        $foreign = $this->makeCenterRow($this->notificationOne, $this->userB);

        $this->actingRequest($this->userA)
            ->postJson('/api/notification_user/mark-all-read')
            ->assertStatus(200)
            ->assertJsonPath('data.marked', 2);

        $this->assertDatabaseHas('notification_user', ['id' => $ownOne->id, 'is_read' => true]);
        $this->assertDatabaseHas('notification_user', ['id' => $ownTwo->id, 'is_read' => true]);
        $this->assertDatabaseHas('notification_user', ['id' => $foreign->id, 'is_read' => false]);

        $remainingUnread = null;
        $this->tenant->run(function () use (&$remainingUnread): void {
            $remainingUnread = NotificationUser::query()
                ->where('user_id', $this->userA->id)
                ->where('is_read', false)
                ->count();
        });

        $this->assertSame(0, $remainingUnread, 'Caller should have no unread rows left after mark-all-read.');
    }

    public function test_mark_all_read_requires_authentication(): void
    {
        $this->postJson('/api/notification_user/mark-all-read')->assertStatus(401);
    }

    // =========================================================================
    // notifications-14 — unique(notification_id, user_id) + FK to user
    // =========================================================================

    public function test_duplicate_live_center_row_is_rejected_by_the_database(): void
    {
        $this->makeCenterRow($this->notificationOne, $this->userA);

        $this->expectException(QueryException::class);

        $this->makeCenterRow($this->notificationOne, $this->userA);
    }

    public function test_soft_deleted_row_does_not_block_redelivery(): void
    {
        $row = $this->makeCenterRow($this->notificationOne, $this->userA);

        $this->tenant->run(function () use ($row): void {
            $row->delete();
        });

        $fresh = $this->makeCenterRow($this->notificationOne, $this->userA);

        $this->assertNotSame($row->id, $fresh->id);
        $this->assertDatabaseHas('notification_user', ['id' => $fresh->id, 'deleted_at' => null]);
    }

    public function test_center_row_for_unknown_user_is_rejected_by_the_foreign_key(): void
    {
        $this->expectException(QueryException::class);

        $this->tenant->run(function (): void {
            NotificationUser::create([
                'notification_id' => $this->notificationOne->id,
                'user_id' => (string) Str::uuid(),
                'is_read' => false,
            ]);
        });
    }
}
