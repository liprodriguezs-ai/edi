<?php

namespace Modules\Access\Services;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Models\AccessEntryType;
use Modules\Access\Models\AccessLog;
use Modules\Access\Models\AccessVerification;
use Modules\Access\Models\BlacklistEntry;
use Modules\Access\Models\BlacklistType;
use Modules\Access\Models\QrType;
use Modules\Access\Models\Visitor;
use Modules\Access\Models\VisitorStatus;
use Modules\Collections\Services\DelinquencyRestrictionService;
use Modules\Housing\Models\UnitUser;
use Modules\SubscriptionPlan\Models\PlanQuota;
use Modules\SubscriptionPlan\Services\QuotaService;
use Modules\UserProfile\Models\UserProfile;

class AccessService
{
    use NotifiesResidents;

    /** Prefix that distinguishes a resident's personal rotating token from a visit token (XXXX-XXXX). */
    public const PERSONAL_QR_PREFIX = 'RES-';

    /** Time-to-live of a personal rotating token, in seconds. */
    private const PERSONAL_QR_TTL_SECONDS = 60;

    public function __construct(
        private readonly DelinquencyRestrictionService $delinquencyRestrictionService,
        private readonly QuotaService $quotaService,
    ) {}

    /**
     * Resolve the caller's personal rotating QR for the CURRENT tenant. Lazily
     * rotates when missing/expired. Returns the token, ISO expiry, and the
     * SERVER-authoritative countdown. firstOrCreate covers users with no profile
     * row yet in this tenant.
     *
     * @return array{token: string, expires_at: string, seconds_remaining: int}
     */
    public function resolveMyQr(User $user): array
    {
        $profile = UserProfile::firstOrCreate(
            ['user_id' => $user->id, 'tenant_id' => tenant('id')],
        );

        $expired = $profile->personal_qr_token === null
            || $profile->personal_qr_expires_at === null
            || $profile->personal_qr_expires_at->isPast();

        if ($expired) {
            $profile->personal_qr_token = $this->generateUniquePersonalToken();
            $profile->personal_qr_expires_at = Carbon::now()->addSeconds(self::PERSONAL_QR_TTL_SECONDS);
            $profile->save();
        }

        $remaining = max(0, (int) Carbon::now()->diffInSeconds($profile->personal_qr_expires_at, false));

        return [
            'token' => $profile->personal_qr_token,
            'expires_at' => $profile->personal_qr_expires_at->toIso8601String(),
            'seconds_remaining' => $remaining,
        ];
    }

    /**
     * RES-XXXX-XXXX token, unique within the current tenant. Bounded loop.
     */
    private function generateUniquePersonalToken(): string
    {
        for ($i = 0; $i < 10; $i++) {
            $token = self::PERSONAL_QR_PREFIX.strtoupper(Str::random(4).'-'.Str::random(4));

            if (! UserProfile::where('personal_qr_token', $token)->exists()) {
                return $token;
            }
        }

        throw new \RuntimeException('No se pudo generar un token único de QR personal.');
    }

    /**
     * Resident self-entry via personal rotating QR. Resolves the profile by token
     * in the current tenant, rejects if missing/expired, runs the blacklist check
     * for parity, and stamps an access_log row with NO visitor_id (already nullable).
     * The personal QR rotates on its own; it is NEVER consumed.
     *
     * @param  array<string, mixed>  $context
     * @return array{valid: bool, reason: string|null, visitor: null, access_log: AccessLog|null, blacklist_match: bool}
     */
    private function handlePersonalScan(string $token, array $context): array
    {
        $profile = UserProfile::where('personal_qr_token', $token)->first();

        if (! $profile || $profile->personal_qr_expires_at === null || $profile->personal_qr_expires_at->isPast()) {
            return ['valid' => false, 'reason' => 'QR personal expirado o inválido', 'visitor' => null, 'access_log' => null, 'blacklist_match' => false];
        }

        $resident = User::withoutGlobalScope(UserTenantScope::class)->find($profile->user_id);
        $residentName = $resident?->name ?? 'Residente';

        // First active unit of the resident in this tenant (null if none).
        $unitId = UnitUser::query()
            ->where('user_id', $profile->user_id)
            ->whereNull('end_date')
            ->value('unit_id');

        if ($this->checkBlacklist($residentName, null)) {
            $deniedLog = $this->denyEntry([
                'visitor_id' => null,
                'unit_id' => $unitId,
                'visitor_name' => $residentName,
                'access_entry_type_id' => AccessEntryType::QR,
                'access_verification_id' => AccessVerification::QR,
                'guard_user_id' => $context['guard_user_id'] ?? null,
            ], 'Residente en lista negra');

            return ['valid' => false, 'reason' => 'Residente en lista negra', 'visitor' => null, 'access_log' => $deniedLog, 'blacklist_match' => true];
        }

        $accessLog = AccessLog::create([
            'visitor_id' => null,
            'unit_id' => $unitId,
            'visitor_name' => $residentName,
            'access_entry_type_id' => AccessEntryType::QR,
            'access_verification_id' => AccessVerification::QR,
            'authorized_by' => null,
            'entered_at' => now(),
            'guard_user_id' => $context['guard_user_id'] ?? null,
        ]);

        return ['valid' => true, 'reason' => null, 'visitor' => null, 'access_log' => $accessLog, 'blacklist_match' => false];
    }

    /**
     * Generate a new QR-based visitor pass.
     *
     * trial-usage-quotas (REQ-CT-01, REQ-CT-02, REQ-CT-04): this is the ONE
     * resident-initiated visit-registration entry point that consumes
     * `visitor` quota — `requestEntry()` (guard-initiated) is explicitly OUT
     * of scope and must never be touched. generateQr() previously had NO
     * transaction; wrapping it here is safe (verified): Visitor carries only
     * GlobalObserver (no creation listeners), generateUniqueToken() is a
     * bounded 10-iteration exists() loop, and nothing inside queues/mails/
     * calls out. Consuming BEFORE the insert, inside this transaction, means
     * a later failure rolls back the increment for free.
     *
     * @param  array<string, mixed>  $data
     */
    public function generateQr(array $data): Visitor
    {
        return DB::transaction(function () use ($data): Visitor {
            // R-DELQ-4 hook 2: first business rule — an OVERDUE unit above threshold
            // cannot generate visitor-access QRs when delinquency.block_visitor_access
            // is on (design §5).
            $this->delinquencyRestrictionService->assertNotRestricted(
                (string) $data['unit_id'],
                DelinquencyRestrictionService::FEATURE_VISITOR_ACCESS,
            );

            $this->quotaService->assertAndConsume(PlanQuota::RESOURCE_VISITOR);

            $qrType = QrType::findOrFail($data['qr_type_id']);
            $pendingStatus = VisitorStatus::findOrFail(VisitorStatus::PENDING);

            $qrToken = $this->generateUniqueToken();
            $expiresAt = $this->resolveExpiry($qrType->id, $data);

            return Visitor::create([
                'unit_id' => $data['unit_id'],
                'created_by_user_id' => $data['created_by_user_id'] ?? null,
                'name' => $data['name'],
                'visitor_motive_id' => $data['visitor_motive_id'],
                'arrives_by_vehicle' => $data['arrives_by_vehicle'] ?? false,
                'plate' => $data['plate'] ?? null,
                'vehicle_model' => $data['vehicle_model'] ?? null,
                'id_document_type' => $data['id_document_type'] ?? null,
                'id_document_number' => $data['id_document_number'] ?? null,
                'qr_token' => $qrToken,
                'qr_type_id' => $qrType->id,
                'qr_expires_at' => $expiresAt,
                'visitor_status_id' => $pendingStatus->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Validate and process a QR scan. Creates an AccessLog entry on success.
     * For single_entry QR, flips visitor_status to used.
     *
     * The whole check-then-act runs inside a transaction holding a row lock on the
     * visitor (§14.5 r2): two concurrent scans of the same single-entry token can
     * never both register an entry — the second one observes the flipped status.
     *
     * Blacklist is ENFORCED (§14.5 r7): a match records a DENIED row in the
     * immutable log and alerts admin + security; no entry is registered and the
     * QR is NOT consumed.
     *
     * @param  array<string, mixed>  $context  Guard user id, etc.
     * @return array{valid: bool, reason: string|null, visitor: Visitor|null, access_log: AccessLog|null, blacklist_match: bool}
     */
    public function scanQr(string $token, array $context = []): array
    {
        return DB::transaction(function () use ($token, $context): array {
            // Personal rotating tokens are RES- prefixed (see resolveMyQr). Branch to
            // the resident self-entry path BEFORE the visitor lookup so a personal
            // scan never collides with the visit-token (XXXX-XXXX) namespace.
            if (str_starts_with($token, self::PERSONAL_QR_PREFIX)) {
                return $this->handlePersonalScan($token, $context);
            }

            $visitor = Visitor::where('qr_token', $token)->lockForUpdate()->first();

            if (! $visitor) {
                return ['valid' => false, 'reason' => 'QR no existe', 'visitor' => null, 'access_log' => null, 'blacklist_match' => false];
            }

            $visitor->load(['unit', 'visitor_status', 'qr_type']);

            if ($visitor->visitor_status_id === VisitorStatus::USED) {
                return ['valid' => false, 'reason' => 'QR ya fue usado', 'visitor' => $visitor, 'access_log' => null, 'blacklist_match' => false];
            }

            if ($visitor->visitor_status_id === VisitorStatus::CANCELLED) {
                return ['valid' => false, 'reason' => 'QR cancelado por el residente', 'visitor' => $visitor, 'access_log' => null, 'blacklist_match' => false];
            }

            if ($visitor->qr_expires_at && $visitor->qr_expires_at->isPast()) {
                if ($visitor->visitor_status_id === VisitorStatus::PENDING) {
                    $visitor->visitor_status_id = VisitorStatus::EXPIRED;
                    $visitor->save();
                }

                return ['valid' => false, 'reason' => 'QR expirado', 'visitor' => $visitor, 'access_log' => null, 'blacklist_match' => false];
            }

            // EVENT QR: válido SOLO el día calendario de qr_expires_at — a diferencia
            // de TIME_BASED, que es una ventana abierta de varios días. El bloque
            // isPast de arriba ya rechazó "después del corte"; acá se rechaza escanear
            // ANTES del día del evento. El límite de día se evalúa en la zona horaria
            // del tenant (no la del server), consistente con Treasury/ExpenseController.
            // Múltiples ingresos ese día.
            $eventTimezone = tenant('timezone') ?: config('app.timezone');
            if ($visitor->qr_type_id === QrType::EVENT
                && $visitor->qr_expires_at
                && ! $visitor->qr_expires_at->copy()->setTimezone($eventTimezone)->isSameDay(now($eventTimezone))) {
                return ['valid' => false, 'reason' => 'QR de evento válido solo el '.$visitor->qr_expires_at->format('d/m/Y'), 'visitor' => $visitor, 'access_log' => null, 'blacklist_match' => false];
            }

            if ($visitor->visitor_status_id !== VisitorStatus::PENDING) {
                return ['valid' => false, 'reason' => 'QR no válido', 'visitor' => $visitor, 'access_log' => null, 'blacklist_match' => false];
            }

            if ($this->checkBlacklist($visitor->name, $visitor->plate)) {
                $deniedLog = $this->denyEntry([
                    'visitor_id' => $visitor->id,
                    'unit_id' => $visitor->unit_id,
                    'visitor_name' => $visitor->name,
                    'access_entry_type_id' => AccessEntryType::QR,
                    'access_verification_id' => AccessVerification::QR,
                    'plate' => $visitor->plate,
                    'visitor_motive_id' => $visitor->visitor_motive_id,
                    'guard_user_id' => $context['guard_user_id'] ?? null,
                ]);

                return ['valid' => false, 'reason' => 'Visitante o vehículo en lista negra', 'visitor' => $visitor, 'access_log' => $deniedLog, 'blacklist_match' => true];
            }

            $accessLog = AccessLog::create([
                'visitor_id' => $visitor->id,
                'unit_id' => $visitor->unit_id,
                'visitor_name' => $visitor->name,
                'access_entry_type_id' => AccessEntryType::QR,
                'access_verification_id' => AccessVerification::QR,
                'authorized_by' => null,
                'entered_at' => now(),
                'plate' => $visitor->plate,
                'id_document_type' => $visitor->id_document_type,
                'id_document_number' => $visitor->id_document_number,
                'visitor_motive_id' => $visitor->visitor_motive_id,
                'guard_user_id' => $context['guard_user_id'] ?? null,
            ]);

            if ($visitor->qr_type_id === QrType::SINGLE_ENTRY) {
                $visitor->visitor_status_id = VisitorStatus::USED;
                $visitor->save();
            }

            // §14.7: visitor arrived (QR scan success) → push to the unit's residents.
            $this->notifyVisitorArrived($visitor->unit_id, $visitor->name);

            return [
                'valid' => true,
                'reason' => null,
                'visitor' => $visitor->fresh(['unit', 'visitor_status', 'visitor_motive']),
                'access_log' => $accessLog,
                'blacklist_match' => false,
            ];
        });
    }

    /**
     * §14.7: push the unit's residents that a visitor arrived. Urgent: the visit
     * is physically at the gate.
     */
    private function notifyVisitorArrived(string $unitId, string $visitorName): void
    {
        $this->notifyEvent(
            'visitor_at_gate',
            $this->unitResidentUserIds($unitId),
            [
                'visitor_name' => $visitorName,
            ],
            'Visita en puerta',
            "El visitante {$visitorName} ha ingresado a tu unidad.",
            [
                'click_action' => '/my-access-log',
                'unit_id' => $unitId,
                'category' => NotificationCategory::ACCESS,
                'urgent' => true,
            ],
        );
    }

    /**
     * Register a manual entry without a prior QR (§14.4.4 fallback path: phone or
     * chat verification already done by the guard).
     *
     * Blacklist is checked FIRST (§14.5 r7 "Al escanear/ESCRIBIR, sistema alerta"):
     * a match records a DENIED row instead of an entry — callers must check
     * denied_at on the returned log.
     *
     * @param  array<string, mixed>  $data
     */
    public function manualEntry(array $data): AccessLog
    {
        if ($this->checkBlacklist($data['visitor_name'], $data['plate'] ?? null)) {
            return $this->denyEntry([
                'visitor_id' => null,
                'unit_id' => $data['unit_id'],
                'visitor_name' => $data['visitor_name'],
                'access_entry_type_id' => AccessEntryType::MANUAL,
                'access_verification_id' => $data['access_verification_id'],
                'authorized_by' => $data['authorized_by'] ?? null,
                'plate' => $data['plate'] ?? null,
                'id_document_type' => $data['id_document_type'] ?? null,
                'id_document_number' => $data['id_document_number'] ?? null,
                'visitor_motive_id' => $data['visitor_motive_id'] ?? null,
                'guard_user_id' => $data['guard_user_id'] ?? null,
            ]);
        }

        $accessLog = AccessLog::create([
            'visitor_id' => null,
            'unit_id' => $data['unit_id'],
            'visitor_name' => $data['visitor_name'],
            'access_entry_type_id' => AccessEntryType::MANUAL,
            'access_verification_id' => $data['access_verification_id'],
            'authorized_by' => $data['authorized_by'] ?? null,
            'entered_at' => now(),
            'plate' => $data['plate'] ?? null,
            'id_document_type' => $data['id_document_type'] ?? null,
            'id_document_number' => $data['id_document_number'] ?? null,
            'visitor_motive_id' => $data['visitor_motive_id'] ?? null,
            'guard_user_id' => $data['guard_user_id'] ?? null,
        ]);

        $this->attachPhoto($accessLog, $data['photo'] ?? null);

        $this->notifyVisitorArrived($data['unit_id'], $data['visitor_name']);

        return $accessLog;
    }

    /**
     * §14.4.4–14.4.5 remote approval flow (server-side contract):
     *  1. The guard registers the visit WITHOUT a prior QR → a Visitor row is
     *     created in pending_approval and the unit's residents get an URGENT push.
     *  2. The resident calls POST visitor/{id}/approve or /reject.
     *  3. The gate polls the visitor status (pending_approval → approved/rejected);
     *     the 60s timeout is handled gate-side, falling back to manualEntry (phone).
     *
     * Blacklist is enforced here too: a match records a DENIED row and no visitor
     * is created.
     *
     * @param  array<string, mixed>  $data
     * @return array{visitor: Visitor|null, denied_log: AccessLog|null}
     */
    public function requestEntry(array $data): array
    {
        if ($this->checkBlacklist($data['name'], $data['plate'] ?? null)) {
            $deniedLog = $this->denyEntry([
                'visitor_id' => null,
                'unit_id' => $data['unit_id'],
                'visitor_name' => $data['name'],
                'access_entry_type_id' => AccessEntryType::MANUAL,
                'access_verification_id' => AccessVerification::APP_REMOTE,
                'plate' => $data['plate'] ?? null,
                'id_document_type' => $data['id_document_type'] ?? null,
                'id_document_number' => $data['id_document_number'] ?? null,
                'visitor_motive_id' => $data['visitor_motive_id'] ?? null,
                'guard_user_id' => $data['guard_user_id'] ?? null,
            ]);

            return ['visitor' => null, 'denied_log' => $deniedLog];
        }

        $visitor = Visitor::create([
            'unit_id' => $data['unit_id'],
            'created_by_user_id' => $data['guard_user_id'] ?? null,
            'name' => $data['name'],
            'visitor_motive_id' => $data['visitor_motive_id'],
            'arrives_by_vehicle' => ! empty($data['plate']),
            'plate' => $data['plate'] ?? null,
            'vehicle_model' => $data['vehicle_model'] ?? null,
            'id_document_type' => $data['id_document_type'] ?? null,
            'id_document_number' => $data['id_document_number'] ?? null,
            'qr_token' => $this->generateUniqueToken(),
            'qr_type_id' => QrType::SINGLE_ENTRY,
            'qr_expires_at' => Carbon::now($this->tenantTimezone())->endOfDay(),
            'visitor_status_id' => VisitorStatus::PENDING_APPROVAL,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->attachPhoto($visitor, $data['photo'] ?? null);

        // §14.4.5: interactive push to the unit's residents — the visit is waiting
        // at the gate, so it bypasses quiet hours.
        $this->notifyUsers(
            $this->unitResidentUserIds($visitor->unit_id),
            'Visita en caseta',
            "'{$visitor->name}' pide entrar a tu unidad. ¿Autorizas su entrada?",
            [
                'click_action' => '/visitor',
                'visitor_id' => $visitor->id,
                'unit_id' => $visitor->unit_id,
                'category' => NotificationCategory::ACCESS,
                'urgent' => true,
            ],
        );

        return ['visitor' => $visitor, 'denied_log' => null];
    }

    /**
     * Resident approves a pending entry request (§14.4.5). Stamps the entry in the
     * immutable log with app_remote verification + the approver as authorizer, and
     * alerts the gate. Lock-guarded: a concurrent approve/reject loses the race and
     * returns null.
     */
    public function approveEntry(Visitor $visitor, User $approver): ?AccessLog
    {
        return DB::transaction(function () use ($visitor, $approver): ?AccessLog {
            $locked = Visitor::whereKey($visitor->id)->lockForUpdate()->first();

            if (! $locked || $locked->visitor_status_id !== VisitorStatus::PENDING_APPROVAL) {
                return null;
            }

            $locked->visitor_status_id = VisitorStatus::APPROVED;
            $locked->save();

            $accessLog = AccessLog::create([
                'visitor_id' => $locked->id,
                'unit_id' => $locked->unit_id,
                'visitor_name' => $locked->name,
                'access_entry_type_id' => AccessEntryType::MANUAL,
                'access_verification_id' => AccessVerification::APP_REMOTE,
                'authorized_by' => $approver->name,
                'entered_at' => now(),
                'plate' => $locked->plate,
                'id_document_type' => $locked->id_document_type,
                'id_document_number' => $locked->id_document_number,
                'visitor_motive_id' => $locked->visitor_motive_id,
                'guard_user_id' => $approver->id,
            ]);

            // §14.7: resident authorized → push to the guards waiting at the gate.
            $this->notifyUsers(
                $this->userIdsWithRole('security'),
                'Entrada autorizada',
                "El residente autorizó la entrada de '{$locked->name}'.",
                [
                    'click_action' => '/access-log',
                    'access_log_id' => $accessLog->id,
                    'visitor_id' => $locked->id,
                    'category' => NotificationCategory::ACCESS,
                    'urgent' => true,
                ],
            );

            return $accessLog;
        });
    }

    /**
     * Resident rejects a pending entry request (§14.4.5). Records the denial in the
     * immutable log (no entry stamped) and alerts the gate.
     */
    public function rejectEntry(Visitor $visitor, User $rejecter): ?AccessLog
    {
        return DB::transaction(function () use ($visitor, $rejecter): ?AccessLog {
            $locked = Visitor::whereKey($visitor->id)->lockForUpdate()->first();

            if (! $locked || $locked->visitor_status_id !== VisitorStatus::PENDING_APPROVAL) {
                return null;
            }

            $locked->visitor_status_id = VisitorStatus::REJECTED;
            $locked->save();

            $deniedLog = AccessLog::create([
                'visitor_id' => $locked->id,
                'unit_id' => $locked->unit_id,
                'visitor_name' => $locked->name,
                'access_entry_type_id' => AccessEntryType::MANUAL,
                'access_verification_id' => AccessVerification::APP_REMOTE,
                'authorized_by' => $rejecter->name,
                'entered_at' => null,
                'denied_at' => now(),
                'denied_reason' => 'Rechazado por el residente',
                'plate' => $locked->plate,
                'id_document_type' => $locked->id_document_type,
                'id_document_number' => $locked->id_document_number,
                'visitor_motive_id' => $locked->visitor_motive_id,
            ]);

            // §14.7: resident rejected → push to the guards waiting at the gate.
            $this->notifyUsers(
                $this->userIdsWithRole('security'),
                'Entrada rechazada',
                "El residente rechazó la entrada de '{$locked->name}'. No permitir el acceso.",
                [
                    'click_action' => '/access-log',
                    'access_log_id' => $deniedLog->id,
                    'visitor_id' => $locked->id,
                    'category' => NotificationCategory::ACCESS,
                    'urgent' => true,
                ],
            );

            return $deniedLog;
        });
    }

    /**
     * Mark exit for an access log entry.
     */
    public function checkout(AccessLog $accessLog): AccessLog
    {
        $accessLog->exited_at = now();
        $accessLog->save();

        // §14.7: visitor exited → push to the unit's residents.
        if ($accessLog->unit_id) {
            $this->notifyUsers(
                $this->unitResidentUserIds($accessLog->unit_id),
                'Visita registró salida',
                'Tu visitante ha salido del condominio.',
                [
                    'click_action' => '/my-access-log',
                    'access_log_id' => $accessLog->id,
                    'unit_id' => $accessLog->unit_id,
                    'category' => NotificationCategory::ACCESS,
                ],
            );
        }

        return $accessLog;
    }

    /**
     * Add an immutable correction note to an access log entry.
     * Does NOT replace the existing correction; appends with timestamp.
     */
    public function addCorrection(AccessLog $accessLog, string $note): AccessLog
    {
        $timestamp = now()->toDateTimeString();
        $existing = $accessLog->correction ? $accessLog->correction."\n" : '';
        $accessLog->correction = $existing."[{$timestamp}] {$note}";
        $accessLog->save();

        return $accessLog;
    }

    /**
     * Cancel a pending QR before it is used.
     */
    public function cancelQr(Visitor $visitor): Visitor
    {
        if ($visitor->visitor_status_id !== VisitorStatus::PENDING) {
            return $visitor;
        }

        $visitor->visitor_status_id = VisitorStatus::CANCELLED;
        $visitor->save();

        // §14.7: QR cancelled by resident → push to the guards (security role).
        $this->notifyUsers(
            $this->userIdsWithRole('security'),
            'QR de visita cancelado',
            "El residente canceló el acceso del visitante '{$visitor->name}'.",
            [
                'click_action' => '/visitor',
                'visitor_id' => $visitor->id,
                'unit_id' => $visitor->unit_id,
                'category' => NotificationCategory::ACCESS,
            ],
        );

        return $visitor;
    }

    /**
     * Check whether a visitor name or vehicle plate is on the active blacklist.
     */
    public function checkBlacklist(?string $name, ?string $plate): bool
    {
        $visitorType = BlacklistType::find(BlacklistType::VISITOR);
        $vehicleType = BlacklistType::find(BlacklistType::VEHICLE);

        if ($name && $visitorType) {
            $match = BlacklistEntry::where('blacklist_type_id', $visitorType->id)
                ->where('is_active', true)
                ->whereRaw('LOWER(value) = LOWER(?)', [$name])
                ->exists();
            if ($match) {
                return true;
            }
        }

        if ($plate && $vehicleType) {
            $match = BlacklistEntry::where('blacklist_type_id', $vehicleType->id)
                ->where('is_active', true)
                ->whereRaw('LOWER(value) = LOWER(?)', [$plate])
                ->exists();
            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record a DENIED attempt in the immutable log (§14.5 r7) and alert
     * administrators + security URGENTLY — the person is at the gate.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function denyEntry(array $attributes, string $reason = 'Coincidencia en lista negra'): AccessLog
    {
        $deniedLog = AccessLog::create(array_merge($attributes, [
            'entered_at' => null,
            'denied_at' => now(),
            'denied_reason' => $reason,
        ]));

        $this->notifyUsers(
            $this->userIdsWithRole(['administrator', 'security']),
            'Alerta de lista negra',
            "Entrada denegada a '{$deniedLog->visitor_name}': {$reason}.",
            [
                'click_action' => '/access-log',
                'access_log_id' => $deniedLog->id,
                'unit_id' => $deniedLog->unit_id,
                'category' => NotificationCategory::ACCESS,
                'urgent' => true,
            ],
        );

        return $deniedLog;
    }

    /**
     * Attach an optional gate photo to a Visitor or AccessLog (both expose the
     * 'photo' single-file media collection).
     */
    private function attachPhoto(Visitor|AccessLog $model, mixed $photo): void
    {
        if ($photo instanceof UploadedFile) {
            $model->addMedia($photo)->toMediaCollection('photo');
        }
    }

    /**
     * Resolve QR expiry timestamp based on QR type.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveExpiry(string $qrTypeKey, array $data): ?Carbon
    {
        $tz = $this->tenantTimezone();

        return match ($qrTypeKey) {
            QrType::SINGLE_ENTRY => Carbon::now($tz)->endOfDay(),
            QrType::TIME_BASED => isset($data['qr_expires_at'])
                ? Carbon::parse($data['qr_expires_at'], $tz)
                : Carbon::now($tz)->addHours(24),
            QrType::EVENT => isset($data['qr_expires_at'])
                ? Carbon::parse($data['qr_expires_at'], $tz)
                : null,
            default => null,
        };
    }

    /**
     * Resolve the active tenant's timezone, falling back to the app default.
     * QR expiry end-of-day must land at the cluster's local midnight, not UTC.
     */
    private function tenantTimezone(): string
    {
        return tenant('timezone') ?: config('app.timezone');
    }

    /**
     * Generate a unique QR token for the current tenant.
     * Bounded to 10 attempts to prevent an infinite loop if the token space saturates.
     */
    private function generateUniqueToken(): string
    {
        $maxAttempts = 10;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $token = strtoupper(Str::random(4).'-'.Str::random(4));

            if (! Visitor::where('qr_token', $token)->exists()) {
                return $token;
            }
        }

        throw new \RuntimeException('No se pudo generar un token único de QR.');
    }

    /**
     * Get all visitors currently inside (entered, no exit, not denied) — §14.4.1
     * "Visitantes ahora" / §14.4.6 exit selector.
     *
     * @return Collection<int, AccessLog>
     */
    public function currentlyInside(): Collection
    {
        return AccessLog::whereNull('exited_at')
            ->whereNotNull('entered_at')
            ->whereNull('denied_at')
            ->with(['visitor', 'unit', 'access_entry_type', 'visitor_motive'])
            ->orderByDesc('entered_at')
            ->get();
    }

    /**
     * §18.6 — Soft-delete visitors whose QR expired more than $days ago AND that have
     * NO access_log entry (the QR was never actually used). The immutable access_log is
     * NEVER touched: visitors that ever entered keep their record forever.
     *
     * Runs in the CURRENT tenant context (the caller iterates tenants). Returns the
     * number of visitors pruned.
     */
    public function pruneExpiredQr(int $days = 30): int
    {
        $cutoff = Carbon::now()->subDays($days);

        // Prune ONLY visitors whose QR expired AND were NEVER used (no access_log
        // row). A visitor that ever entered keeps its record forever — the
        // immutable access_log references it (§14.5 r6), so pruning a used visitor
        // would orphan that audit trail. Used/time/event QRs scanned at least once
        // therefore accumulate by design (they carry real history).
        $stale = Visitor::query()
            ->whereNotNull('qr_expires_at')
            ->where('qr_expires_at', '<', $cutoff)
            ->whereDoesntHave('access_logs')
            ->get();

        $count = 0;
        foreach ($stale as $visitor) {
            $visitor->delete();
            $count++;
        }

        return $count;
    }
}
