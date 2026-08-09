# Queued channel delivery (Cambio 6, 2026-06-28) + click_action rules (Cambio 4)

## Delivery is QUEUED per-recipient

ALL external-I/O channel deliveries are async + retryable via `Modules\Notification\Jobs\DeliverChannelMessageJob`
(`ShouldQueue`, `$tries=3`, `backoff [10,30,60]`, `failed()→report()`).
- `NotificationDispatcher::dispatch()` resolves addresses + filters prefs/quiet-hours **INLINE** (request,
  correct tenant ctx), then enqueues **one job per (channel × recipient)** with the address ALREADY resolved
  (job does NO tenant-scoped queries → worker-safe). Per-recipient so a retry never re-sends to recipients
  that already succeeded, and each email gets its own `To` (privacy).
- `log` channel + `recordInApp` stay **SYNCHRONOUS** (instant bell badge). `log` is the only channel NOT in
  `CHANNEL_ADDRESS_MAP` → that's the split: address-map channels → queued; `log` → inline.
- Channel-name validation stays synchronous in `dispatch()` (`assertChannelRegistered`). Job rebuilds the
  channel via `NotificationDispatcher::makeChannel($name)` (public; container-resolved so test spies apply).
- **Push collapsed**: `SendPushNotificationJob` DELETED; FCM `sendMulticast` moved INTO
  `FirebasePushChannel::send()` (synchronous, runs inside the queued job).
- **Real retry**: removed swallowing try/catch in `BladeEmailChannel`, `SmsChannel` AND `ResendEmailChannel`
  (last one missed in first pass, caught in review; added `->throw()` to the Twilio Http call) so transport
  errors PROPAGATE and the job retries. FCM per-token failures logged (not thrown — permanent).
  Template-missing→plaintext (Blade) + credential/no-address guards are NOT errors and stay.
- **Test caveat**: `QUEUE_CONNECTION=sync` runs jobs inline EXCEPT under `Queue::fake()` (faked jobs never
  execute). Assert ENQUEUE with `Queue::fake()` + `assertPushed(DeliverChannelMessageJob, N)`; assert EFFECT
  by NOT faking the queue (sync runs the job inline). Spy-based NotificationDeliveryTest/NotificationPreferenceTest
  pass unchanged because they don't fake the queue.

## Deep-link `click_action` MUST start with `/`

Bell + toast only navigate when `click_action` starts with `/`. ALL emitters audited to REAL frontend
routes (from `frontend/src/App.tsx`). Map:
- payments (`PaymentService` register/submit/validate/reject, `GenerateReceiptPdfJob`) → `'/payment/'.$id`
- credit_balance, charge reminders/overdue, new-charge (resident) → `/statement`; `GenerateMonthlyChargesCommand` (treasurer) → `/charge-batch`
- Treasury: expense→`/expense`, low balance→`/bank-account`, monthly summary→`/tableros/tesoreria`
- Providers→`/provider`; Housing: vehicle→`/vehicle`, unit (resident)→`/my-unit`; Collaborator: hired(self)→`/collaborator/me`, terminated(admins)→`'/collaborator/'.$id`
- Announcements→`/announcement`; Board→`/board-period`; Chat→`/chat-channel`; Reports→`/reports`; Projects→`'/community-project/'.$id`
- Access: residents→`/my-access-log`, security/admin→`/access-log`, visitor actions→`/visitor`
- Amenities: resident→`/my-reservations`, board/admin→`/amenity-reservation`

RULE: any new emitter's `click_action` must be an existing `/`-route (detail route if it exists, else the
closest list route).
