<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DispatchesNotifications;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;
use Modules\Tenant\Models\Tenant;
use Tests\Support\WithTenantSetup;
use Tests\TestCase;

/**
 * Fase 3 — recordInApp fans out the in-app rows with ONE bulk insert (1 INSERT for
 * N recipients) instead of N firstOrCreate round-trips. Semantics are unchanged:
 * 1 Notification + one notification_user (is_read=false) per recipient, deduped.
 */
final class RecordInAppBulkTest extends TestCase
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

    private function harness(): object
    {
        return new class
        {
            use DispatchesNotifications;

            /**
             * @param  array<int, string>  $ids
             */
            public function go(array $ids): void
            {
                $this->notifyUsers($ids, 'Título', 'Cuerpo', [], true, ['log']);
            }
        };
    }

    private function makeUser(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Bulk User '.Str::random(5),
            'email' => Str::random(10).'@test.com',
            'password' => Hash::make('secret1234'),
            'auth_active' => true,
        ]);

        $user->tenants()->attach($tenant->id);

        return $user;
    }

    public function test_record_in_app_is_a_single_bulk_insert_for_n_users(): void
    {
        $tenant = $this->makeTenant();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->makeUser($tenant)->id;
        }

        $harness = $this->harness();

        $tenant->run(function () use ($harness, $ids): void {
            $inserts = 0;
            DB::listen(function ($query) use (&$inserts): void {
                if (str_contains($query->sql, 'insert into "notification_user"')) {
                    $inserts++;
                }
            });

            $harness->go($ids);

            $this->assertSame(1, $inserts, 'N recipients must be ONE bulk insert, not N round-trips');
            $this->assertSame(5, NotificationUser::count(), 'One notification_user row per recipient');
        });
    }

    public function test_duplicate_recipient_ids_collapse_to_unique_rows(): void
    {
        $tenant = $this->makeTenant();
        $a = $this->makeUser($tenant);
        $b = $this->makeUser($tenant);

        $harness = $this->harness();

        $tenant->run(function () use ($harness, $a, $b): void {
            $harness->go([$a->id, $a->id, $b->id]);

            $this->assertSame(2, NotificationUser::count(), 'Duplicate recipient ids must not duplicate rows');
        });
    }

    public function test_notification_date_is_the_tenant_civil_day_start_in_utc(): void
    {
        $tenant = $this->makeTenant();
        $tenant->update(['timezone' => 'America/Mexico_City']);
        $userId = $this->makeUser($tenant)->id;
        $harness = $this->harness();

        // Oct 20 21:00 in Mexico City: the tenant day began at 2026-10-20T06:00Z.
        $this->travelTo(Carbon::parse('2026-10-21T03:00:00Z'));

        $date = $tenant->run(function () use ($harness, $userId) {
            $harness->go([$userId]);

            return Notification::query()->latest('created_at')->value('date');
        });

        $this->assertSame('2026-10-20 06:00:00', $date);
    }
}
