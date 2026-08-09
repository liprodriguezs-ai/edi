<?php

namespace Modules\Notification\Jobs;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationUser;

class NotificationUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Bounded retries so a failure after the in-app rows are written does not
     * re-queue forever (the in-app insert is idempotent via insertOrIgnore).
     */
    public int $tries = 3;

    protected $notification;

    protected $users;

    /**
     * Create a new job instance.
     */
    public function __construct(Notification $notification, Collection $users)
    {
        $this->users = $users;
        $this->notification = $notification;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // convertir el array en collect (coleccion), métodos de colección como pluck(), filter(), map(), etc.
        // establecer el tiempo de ejecución a infinito
        ini_set('max_execution_time', 0);
        // aumentar el limite de la memoria para el script a 2048M
        ini_set('memory_limit', '2048M');

        // obtiene los tokens de todos los usuarios, los filtra y los convierte a array

        $this->createNotificationUsers();

        $usersFilter = $this->users->filter(function ($user) {
            return ! empty($user->token_sender);
        });

        // Honor per-user push preferences + quiet hours before sending FCM. The in-app
        // rows written above are NOT filtered (in-app delivery is always recorded), but a
        // user who disabled push or is within their quiet-hours window must not receive an
        // admin-broadcast push — the same guarantee NotificationDispatcher enforces on the
        // per-event path. Without this, this legacy broadcast path bypassed opt-outs.
        $usersFilter = $this->filterPushRecipients($usersFilter);

        $senders = $usersFilter->pluck('token_sender');

        // verifica que el lenght sea mayor a 0
        if (count($senders) > 0) {
            // only value
            $result = $this->sendNotification($senders->values()->toArray());
            // FCM per-token failures are almost always permanent (invalid /
            // unregistered token), so we log them instead of re-sending — an
            // immediate resend just wastes an FCM call (mirrors FirebasePushChannel).
            if ($result !== null && $result->hasFailures()) {
                $failedTokens = [];
                foreach ($result->failures()->getItems() as $failure) {
                    $failedTokens[] = $failure->target()->value();
                }
                Log::warning('[NotificationUserJob] FCM delivery failed for tokens (not resent).', [
                    'failed_tokens' => $failedTokens,
                ]);
            }
        }
    }

    /**
     * Create one NotificationUser row per recipient via a single idempotent
     * bulk insert. insertOrIgnore respects the partial unique index on
     * (notification_id, user_id) WHERE deleted_at IS NULL, so a job retry does
     * not collide. The bulk insert bypasses model events, so tenant_id, id and
     * timestamps are stamped by hand (mirrors NotifiesResidents::recordInApp).
     */
    public function createNotificationUsers(): void
    {
        $userIds = $this->users
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $now = now();
        $tenantId = tenant()?->getTenantKey();

        $rows = $userIds->map(fn ($userId): array => [
            'id' => (string) Str::uuid(),
            'notification_id' => $this->notification->id,
            'user_id' => $userId,
            'is_read' => false,
            'tenant_id' => $tenantId,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        NotificationUser::insertOrIgnore($rows);
    }

    /**
     * Drop users who opted out of push (global toggle) or are within their quiet-hours
     * window. An admin broadcast carries no category and is treated as NON-urgent, so
     * quiet hours suppress it. Users without a preference row keep the everything-on
     * default. NotificationPreference is a central, per-user model (no tenant scope).
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    private function filterPushRecipients(Collection $users): Collection
    {
        $userIds = $users->pluck('id')->filter()->unique()->values()->all();

        if ($userIds === []) {
            return $users;
        }

        $preferences = NotificationPreference::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        return $users->filter(function ($user) use ($preferences): bool {
            $preference = $preferences->get($user->id);

            if ($preference === null) {
                return true;
            }

            if (! $preference->allowsChannel('push')) {
                return false;
            }

            // Non-urgent broadcast → quiet hours suppress push.
            return ! $preference->isWithinQuietHours();
        });
    }

    public function sendNotification($senderId): ?MulticastSendReport
    {
        $credentials = config('services.firebase.credentials');

        if (! $credentials) {
            Log::warning('[NotificationUserJob] Firebase credentials not configured — skipping push dispatch.');

            return null;
        }

        Log::info('sendNotification', ['senderId' => $senderId]);
        $factory = (new Factory)
            ->withServiceAccount($credentials);
        // limpia los tokens de los usuarios y los nombra
        $devices = array_map('trim', $senderId);
        // se crea una intancia del servicio de mensajeria de firebase
        $cloudMessaging = $factory->createMessaging();
        // se crea una instancia de la clase CloudMessage de firebase con los datos de la notificación que solicita firebase
        $message = CloudMessage::fromArray([
            'notification' => [
                'title' => $this->notification->title,
                'body' => $this->notification->message,
                'sound' => 'default',
                'click_action' => $this->notification->click_action ?? null,
                'icon' => '',
            ],
            'data' => [
                'click_action' => $this->notification->click_action ?? null,
            ],
        ]);

        // se hace el envio multiple a los usuarios(dispositivos)
        return $cloudMessaging->sendMulticast($message, $devices);
    }
}
