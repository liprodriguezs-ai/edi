<?php

namespace Modules\Package\Services;

use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Exceptions\DelinquencyRestrictedException;
use Modules\Collections\Services\DelinquencyRestrictionService;
use Modules\Housing\Models\Unit;
use Modules\Package\Models\Package;
use Modules\Package\Models\PackageStatus;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PackageService
{
    use NotifiesResidents;

    /** Crockford-style alphabet — excludes ambiguous characters I, L, O, 0, 1. */
    private const PICKUP_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly DelinquencyRestrictionService $delinquencyRestrictionService) {}

    /**
     * Per-instance memo for Setting::getValue lookups so a batch of N packages does
     * not re-query the same setting N times. The service is resolved fresh per request
     * (never a singleton), so the cache lifetime is the request.
     *
     * @var array<string, mixed>
     */
    private array $settingCache = [];

    private function getSetting(string $slug): mixed
    {
        return $this->settingCache[$slug] ??= Setting::getValue($slug);
    }

    /**
     * Generate a unique pickup code in XXXX-XXXX format for the current tenant.
     *
     * Bounded loop (10 attempts) mirrors AccessService::generateUniqueToken.
     * The partial unique index on (tenant_id, pickup_code) WHERE deleted_at IS NULL
     * backstops races; this check handles the happy-path without a DB round-trip failure.
     *
     * @throws \RuntimeException when no unique code can be generated in 10 attempts
     */
    private function generateUniquePickupCode(): string
    {
        $alphabet = self::PICKUP_CODE_ALPHABET;
        $length = strlen($alphabet);

        for ($i = 0; $i < 10; $i++) {
            $part1 = '';
            $part2 = '';

            for ($j = 0; $j < 4; $j++) {
                $part1 .= $alphabet[random_int(0, $length - 1)];
                $part2 .= $alphabet[random_int(0, $length - 1)];
            }

            $code = "{$part1}-{$part2}";

            if (! Package::where('pickup_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('No se pudo generar un código de retiro único');
    }

    /**
     * Persist the package, regenerating the pickup_code and retrying on a unique-constraint
     * collision. The exists() pre-check in generateUniquePickupCode covers the happy path;
     * this closes the TOCTOU window between that check and the INSERT (two concurrent
     * register() calls can both observe exists()=false for the same code and race to INSERT;
     * the partial unique index rejects the loser with PostgreSQL SQLSTATE 23505).
     *
     * Each save attempt runs in its own nested transaction (SAVEPOINT) so a unique violation
     * rolls back ONLY the failed INSERT — PostgreSQL aborts the entire surrounding transaction
     * on any statement error otherwise, which would make a naive in-place retry impossible.
     *
     * @throws QueryException when a non-unique-violation error occurs or all attempts collide
     */
    private function persistWithUniquePickupCode(Package $package): void
    {
        if (! $this->getSetting('package.pickup_code_enabled')) {
            $package->save();

            return;
        }

        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $package->pickup_code = $this->generateUniquePickupCode();

            try {
                DB::transaction(function () use ($package): void {
                    $package->save();
                });

                return;
            } catch (QueryException $e) {
                $isUniqueViolation = $e->getCode() === '23505';

                if (! $isUniqueViolation || $attempt === $maxAttempts) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Return the two pickup-policy booleans that guards need before performing a handover.
     *
     * Exposed separately from /config/package-settings so callers who hold
     * update.package (guards, admins) can read these two flags without needing
     * the read.configuration ability that /config/package-settings requires.
     *
     * @return array{require_pickup_code: bool, requires_pickup_signature: bool, requires_pickup_evidence: bool}
     */
    public function getPickupPolicy(): array
    {
        return [
            'require_pickup_code' => (bool) $this->getSetting('package.require_pickup_code'),
            'requires_pickup_signature' => (bool) $this->getSetting('package.requires_pickup_signature'),
            'requires_pickup_evidence' => (bool) $this->getSetting('package.requires_pickup_evidence'),
        ];
    }

    /**
     * Register a single package intake. Wraps in a DB transaction.
     *
     * Steps:
     * 1. Build package from $data (excluding media fields).
     * 2. Stamp received_at and received_by_user_id.
     * 3. Set status to PackageStatus::READY.
     * 4. Conditionally generate pickup_code.
     * 5. Save.
     * 6. Attach photo media if present.
     * 7. Conditionally notify active unit residents (deferred via DB::afterCommit).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>|null  $residentUserIds  pre-resolved active residents for
     *                                                    this unit (batch path); null = resolve here
     * @param  array<int, string>|null  $preCheckedRestrictedUnitIds  batch path only: the full
     *                                                                restricted-unit-id set `batchRegister()` already computed via ONE
     *                                                                `restrictedUnitIds()` call. When given, the guard below becomes an
     *                                                                in-memory `in_array` instead of the 1-2 SELECTs `assertNotRestricted()`
     *                                                                would otherwise run per row (infra-cost finding — a 50-row batch was
     *                                                                issuing up to ~100 extra queries). null = direct callers (single
     *                                                                intake) still get the real, defense-in-depth DB check.
     */
    public function register(array $data, User $guard, ?array $residentUserIds = null, ?array $preCheckedRestrictedUnitIds = null): Package
    {
        return DB::transaction(function () use ($data, $guard, $residentUserIds, $preCheckedRestrictedUnitIds): Package {
            // R-DELQ-4 hook 4: first statement in the transaction, before any write —
            // an OVERDUE unit above threshold cannot receive packages when
            // delinquency.block_package_intake is on. Guard-facing payload, no
            // amount/notice_text (design §5).
            if ($preCheckedRestrictedUnitIds !== null) {
                if (in_array((string) $data['unit_id'], $preCheckedRestrictedUnitIds, true)) {
                    throw DelinquencyRestrictedException::forGuard();
                }
            } else {
                $this->delinquencyRestrictionService->assertNotRestricted(
                    (string) $data['unit_id'],
                    DelinquencyRestrictionService::FEATURE_PACKAGE_INTAKE,
                );
            }

            $package = new Package(Arr::except($data, ['photo']));
            $package->received_at = now();
            $package->received_by_user_id = $guard->id;
            $package->package_status_id = PackageStatus::READY;

            $this->persistWithUniquePickupCode($package);

            // NOTE (accepted tradeoff, design §5): media is attached inside the
            // transaction. Spatie writes the physical file to disk (non-transactional)
            // then inserts the media row (transactional). A later rollback strands the
            // already-written file with no DB row. Compensated by the scheduled
            // `media:clean` job (routes/console.php) which prunes orphaned media.
            if (isset($data['photo']) && $data['photo'] instanceof UploadedFile) {
                $package->addMedia($data['photo'])->toMediaCollection('photo');
            }

            $this->maybeNotifyArrival($package, $residentUserIds);

            return $package;
        });
    }

    /**
     * Register multiple packages in one atomic transaction.
     * If any row fails, the whole batch rolls back.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, Package>
     */
    public function batchRegister(array $rows, User $guard): array
    {
        // R-DELQ-4 hook 5: pre-transaction guard, BEFORE opening the outer
        // transaction — fails fast on ANY restricted unit in the batch, so no
        // resident pre-resolution or savepoints are burned before rejecting
        // (design §5, ADR-6). register()'s own assert stays as defense in depth.
        $unitIds = array_values(array_unique(array_column($rows, 'unit_id')));
        $restrictedUnitIds = $this->delinquencyRestrictionService->restrictedUnitIds(
            DelinquencyRestrictionService::FEATURE_PACKAGE_INTAKE,
        );

        if (array_intersect($unitIds, $restrictedUnitIds) !== []) {
            throw DelinquencyRestrictedException::forGuard();
        }

        return DB::transaction(function () use ($rows, $guard, $restrictedUnitIds): array {
            $created = [];

            // Pre-resolve active residents once per UNIQUE unit so multiple rows for the
            // same unit don't re-query unit_user. Only resolve when arrival notifications
            // are enabled — otherwise the ids are never used.
            $residentsByUnit = [];
            if ($this->getSetting('package.auto_notify_on_arrival')) {
                foreach (array_unique(array_column($rows, 'unit_id')) as $unitId) {
                    $residentsByUnit[$unitId] = $this->unitResidentUserIds($unitId);
                }
            }

            foreach ($rows as $row) {
                // register() opens its own DB::transaction (savepoint inside the outer one).
                // If any row throws, the savepoint rolls back and propagates; the outer
                // transaction rolls back everything — correct batch atomicity.
                // $restrictedUnitIds (already computed once above) turns register()'s
                // per-row delinquency check into an in-memory in_array (infra-cost fix) —
                // still genuine defense-in-depth, just no re-query per row.
                $created[] = $this->register($row, $guard, $residentsByUnit[$row['unit_id']] ?? null, $restrictedUnitIds);
            }

            return $created;
        });
    }

    // ── WU-2 — Pickup & Custody ──────────────────────────────────────────────

    /**
     * Verify a pickup code and return all ready packages for the same unit.
     *
     * Returns a structured array (never exposes pickup_code). Aborts 404 when
     * the code is not found or 422 when the matched package is already in a
     * terminal state.
     *
     * @return array{valid: bool, unit: array<string, mixed>|null, packages: list<array<string, mixed>>}
     *
     * @throws HttpException
     */
    public function verifyPickupCode(string $code): array
    {
        // Only eager-load what is actually read: unit.unitType + unit.tower feed
        // Unit::full_identifier. carrier/status on $found are never accessed (the
        // terminal check uses the raw FK; carrier for the returned list comes from
        // the second query below) — loading them was 2 wasted SELECTs per QR scan.
        /** @var Package|null $found */
        $found = Package::where('pickup_code', $code)
            ->with(['unit.unitType', 'unit.tower'])
            ->first();

        if (! $found) {
            abort(404, 'Código no encontrado');
        }

        $terminalStatuses = [PackageStatus::PICKED_UP, PackageStatus::RETURNED, PackageStatus::INCIDENT];

        if (in_array($found->package_status_id, $terminalStatuses, true)) {
            abort(422, 'El paquete no está disponible para retiro');
        }

        // Return ALL ready packages for the same unit — not just the matched one.
        // pickup_code is in $hidden on Package; we never call makeVisible here.
        $packages = Package::where('unit_id', $found->unit_id)
            ->where('package_status_id', PackageStatus::READY)
            ->with(['carrier', 'status'])
            ->get();

        return [
            'valid' => true,
            'unit' => $found->unit ? [
                'id' => $found->unit->id,
                'full_identifier' => $found->unit->full_identifier,
            ] : null,
            'packages' => $packages->map(fn (Package $pkg): array => [
                'id' => $pkg->id,
                'description' => $pkg->description,
                'storage_location' => $pkg->storage_location,
                'received_at' => $pkg->received_at,
                'tracking_number' => $pkg->tracking_number,
                'package_carrier' => $pkg->carrier ? [
                    'id' => $pkg->carrier->id,
                    'name' => $pkg->carrier->name,
                ] : null,
                'package_status' => $pkg->status ? [
                    'id' => $pkg->status->id,
                    'name' => $pkg->status->name,
                ] : null,
            ])->values()->all(),
        ];
    }

    /**
     * Hand over one or more READY packages to a resident or their proxy in one
     * atomic transaction.
     *
     * Pre-flight: policy checks run BEFORE the transaction so a policy violation
     * aborts immediately without consuming a DB transaction slot.
     *
     * @param  array<int, string>  $packageIds
     * @param  array<string, mixed>  $data
     *
     * @throws HttpException
     */
    public function handover(array $packageIds, array $data, User $guard): Collection
    {
        // Policy pre-flight (must happen before the transaction for a clean 422).
        if ($this->getSetting('package.requires_pickup_signature') && ! isset($data['pickup_signature'])) {
            abort(422, 'Se requiere la firma de retiro (campo: pickup_signature)');
        }

        if ($this->getSetting('package.requires_pickup_evidence') && ! isset($data['pickup_evidence'])) {
            abort(422, 'Se requiere evidencia fotográfica de retiro (campo: pickup_evidence)');
        }

        // When require_pickup_code is on, the guard must supply the code and every
        // submitted package_id must belong to the unit the code resolves to (prevents
        // a guard from bundling packages from multiple units in a single handover call).
        if ($this->getSetting('package.require_pickup_code')) {
            $code = trim((string) ($data['pickup_code'] ?? ''));

            if ($code === '') {
                abort(422, 'Se requiere el código de retiro (campo: pickup_code)');
            }

            // Resolve the code to a unit using the same lookup as verifyPickupCode.
            // Must resolve to a READY package — codes are never rotated on pickup, so
            // without this filter a terminal package's stale code would still satisfy
            // the gate for a different, still-READY package of the same unit.
            $codePackage = Package::where('pickup_code', $code)
                ->where('package_status_id', PackageStatus::READY)
                ->first();

            if (! $codePackage) {
                abort(422, 'El código de retiro no es válido');
            }

            $resolvedUnitId = $codePackage->unit_id;

            // Single-unit constraint: all package_ids must share one unit_id.
            // This ensures proxy-name, proxy-relationship, and the code itself are coherent.
            $unitIds = Package::whereIn('id', $packageIds)->pluck('unit_id')->unique();

            if ($unitIds->count() > 1) {
                abort(422, 'Todos los paquetes deben pertenecer a la misma unidad en una entrega');
            }

            // Code must resolve to the same unit as the submitted packages.
            if ($unitIds->first() !== $resolvedUnitId) {
                abort(422, 'El código de retiro no corresponde a la unidad de los paquetes');
            }
        }

        return DB::transaction(function () use ($packageIds, $data, $guard): Collection {
            // Tenant-scoped load — BelongsToTenant global scope filters to current tenant.
            // lockForUpdate() serializes concurrent handovers of the same package(s): a
            // second concurrent transaction blocks here until the first commits, then
            // the READY re-check below runs against the now-updated row and fails —
            // closing the custody race where two concurrent handovers both read READY.
            $packages = Package::whereIn('id', $packageIds)->lockForUpdate()->get();

            if ($packages->count() !== count($packageIds)) {
                abort(422, 'Uno o más paquetes no fueron encontrados en este condominio');
            }

            foreach ($packages as $pkg) {
                if ($pkg->package_status_id !== PackageStatus::READY) {
                    abort(422, "El paquete {$pkg->id} no está disponible para entrega");
                }
            }

            $now = now();

            foreach ($packages as $pkg) {
                $pkg->picked_up_at = $now;
                $pkg->picked_up_by_user_id = $guard->id;
                $pkg->pickup_proxy_name = $data['pickup_proxy_name'] ?? null;
                $pkg->pickup_proxy_relationship = $data['pickup_proxy_relationship'] ?? null;
                $pkg->package_status_id = PackageStatus::PICKED_UP;
                $pkg->save();

                // Copy each uploaded file to each package's pickup_evidence collection.
                // preservingOriginal() keeps the temp file accessible for the next iteration.
                // NOTE (accepted tradeoff, design §5): these copies run inside the transaction.
                // A rollback after an earlier iteration's copy strands the physical file with
                // no DB row; the scheduled `media-library:clean` job (routes/console.php) prunes it.
                if (isset($data['pickup_evidence']) && $data['pickup_evidence'] instanceof UploadedFile) {
                    $pkg->addMedia($data['pickup_evidence'])
                        ->preservingOriginal()
                        ->toMediaCollection('pickup_evidence');
                }

                if (isset($data['pickup_signature']) && $data['pickup_signature'] instanceof UploadedFile) {
                    $pkg->addMedia($data['pickup_signature'])
                        ->preservingOriginal()
                        ->toMediaCollection('pickup_evidence');
                }
            }

            // Notify residents of EVERY distinct unit in the batch — package_ids may
            // span multiple units (the API does not constrain them to one), so notifying
            // only the first unit would silently drop notifications for the rest.
            // notifyUsers() defers via DB::afterCommit so a notification failure cannot
            // roll back the core handover write.
            foreach ($packages->pluck('unit_id')->filter()->unique() as $unitId) {
                $residentIds = $this->unitResidentUserIds($unitId);
                if (! empty($residentIds)) {
                    $this->notifyUsers(
                        $residentIds,
                        'Paquete retirado',
                        'Tu paquete ha sido retirado de la portería.',
                        ['click_action' => '/my-packages'],
                    );
                }
            }

            return $packages;
        });
    }

    /**
     * Return a package that was not picked up (changes status to RETURNED).
     * Only packages in READY status can be returned.
     *
     * @throws HttpException
     */
    public function returnPackage(Package $package, string $reason): Package
    {
        if ($package->package_status_id !== PackageStatus::READY) {
            abort(422, 'Solo se pueden devolver paquetes en estado listo (ready)');
        }

        $package->package_status_id = PackageStatus::RETURNED;
        $package->incident_reason = $reason;
        $package->save();

        // Notify the unit's active residents so they know the package was returned.
        // notifyUsers defers via DB::afterCommit — a notification failure never rolls back.
        $residentIds = $this->unitResidentUserIds($package->unit_id);
        if (! empty($residentIds)) {
            $this->notifyUsers(
                $residentIds,
                'Paquete devuelto',
                'Tu paquete fue devuelto al remitente.',
                ['click_action' => '/my-packages', 'package_id' => $package->id],
            );
        }

        return $package;
    }

    /**
     * Flag a package as having an incident (damaged, lost, disputed).
     * Only packages in READY status can be flagged.
     *
     * @throws HttpException
     */
    public function flagIncident(Package $package, string $reason): Package
    {
        if ($package->package_status_id !== PackageStatus::READY) {
            abort(422, 'Solo se pueden registrar incidentes en paquetes en estado listo (ready)');
        }

        $package->package_status_id = PackageStatus::INCIDENT;
        $package->incident_reason = $reason;
        $package->save();

        // Notify the unit's residents about the incident so they can follow up.
        // notifyUsers defers via DB::afterCommit — a notification failure never rolls back.
        $residentIds = $this->unitResidentUserIds($package->unit_id);
        if (! empty($residentIds)) {
            $this->notifyUsers(
                $residentIds,
                'Incidente con tu paquete',
                "Incidente con tu paquete: {$reason}",
                ['click_action' => '/my-packages', 'package_id' => $package->id],
            );
        }

        return $package;
    }

    /**
     * Reassign a misdelivered package to the correct unit in the same tenant.
     *
     * Chain of custody is preserved: received_at, received_by_user_id, and
     * pickup_code are NOT reset. GlobalObserver records the unit_id change in
     * the audit trail automatically.
     *
     * @throws HttpException
     */
    public function reassignUnit(Package $package, string $newUnitId): Package
    {
        // Verify the target unit belongs to the current tenant (BelongsToTenant scope).
        $unit = Unit::find($newUnitId);

        if (! $unit) {
            abort(422, 'La unidad destino no existe en este condominio');
        }

        $terminalStatuses = [PackageStatus::PICKED_UP, PackageStatus::RETURNED, PackageStatus::INCIDENT];

        if (in_array($package->package_status_id, $terminalStatuses, true)) {
            abort(422, 'No se puede reasignar un paquete en estado terminal');
        }

        $oldUnitId = $package->unit_id;

        $package->unit_id = $newUnitId;
        $package->save(); // GlobalObserver captures the unit_id change in the audit trail.

        // Notify the OLD unit's residents that the package no longer belongs to them.
        // Always sent (not gated by auto_notify_on_arrival) — a reassignment is a
        // corrective action residents should always be informed of.
        $oldResidentIds = $this->unitResidentUserIds($oldUnitId);
        if (! empty($oldResidentIds)) {
            $this->notifyUsers(
                $oldResidentIds,
                'Paquete reasignado',
                'El paquete ya no está asignado a tu unidad.',
                ['click_action' => '/my-packages'],
            );
        }

        // Notify the NEW unit's residents that a package has arrived.
        $newResidentIds = $this->unitResidentUserIds($newUnitId);
        if (! empty($newResidentIds)) {
            $this->notifyUsers(
                $newResidentIds,
                'Paquete recibido en portería',
                'Tienes un paquete esperándote en la portería. Retíralo antes de que expire el plazo.',
                ['click_action' => '/my-packages', 'package_id' => $package->id],
            );
        }

        return $package;
    }

    // ── WU-4 — Retention cron ────────────────────────────────────────────────

    /**
     * Process daily retention for the CURRENT tenant context (the command calls this
     * inside `$tenant->run(...)`). Mirrors ChargeStatusService::refreshAllOpenCharges —
     * ALL retention business logic lives here, next to the sibling notification methods,
     * not in the thin command (design §6.3).
     *
     * For each READY package:
     *   - age >= return_after_days   → returnPackage() (RETURNED). Takes precedence.
     *   - age >= reminder_after_days AND notified_at set AND reminded_at null
     *       → dispatch a reminder and stamp reminded_at (exactly once).
     *
     * Memory is bounded via chunkById(200) — chunkById (NOT chunk): returnPackage mutates
     * package_status_id, the very column the WHERE filters on, so offset-based chunk() would
     * skip rows as the result set shrinks between pages; keyset chunkById is immune. cursor()
     * is not an option (incompatible with the carrier eager-load).
     *
     * @return array{reminded: int, returned: int}
     */
    public function processRetention(Tenant $tenant): array
    {
        // Batch-read both day settings in ONE query (cost): Setting::getValue is uncached,
        // so two getValue() calls would fire 2 SELECTs per tenant where 1 suffices.
        $settings = Setting::whereIn('slug', ['package.reminder_after_days', 'package.return_after_days'])
            ->pluck('value', 'slug');
        $reminderAfterDays = (int) ($settings['package.reminder_after_days'] ?? 3);
        $returnAfterDays = (int) ($settings['package.return_after_days'] ?? 10);
        $tenantTimezone = (string) ($tenant->timezone ?? 'America/Mexico_City');

        $reminded = 0;
        $returned = 0;

        Package::where('package_status_id', PackageStatus::READY)
            ->with(['carrier'])
            ->chunkById(200, function (Collection $packages) use (
                $reminderAfterDays,
                $returnAfterDays,
                $tenantTimezone,
                &$reminded,
                &$returned,
            ): void {
                foreach ($packages as $package) {
                    // $receivedAt->diffInDays($now) is always non-negative (past → future),
                    // avoiding the collections-report-signed-diffindays bug.
                    $receivedAt = Carbon::parse($package->received_at)->setTimezone($tenantTimezone);
                    $now = Carbon::now($tenantTimezone);
                    $days = (int) $receivedAt->diffInDays($now);

                    // Return takes precedence — a package past the return window is returned
                    // immediately and skips the reminder step for this package.
                    if ($days >= $returnAfterDays) {
                        $this->returnPackage($package, 'Plazo de retención expirado');
                        $returned++;

                        continue;
                    }

                    // Reminder (send once): threshold reached, arrival already notified
                    // (notified_at set), and no reminder yet (reminded_at null — dedup guard).
                    // dispatchRetentionReminder stamps reminded_at BEFORE dispatching and only
                    // when residents exist (returns false otherwise, leaving reminded_at null).
                    if ($days >= $reminderAfterDays && $package->notified_at !== null && $package->reminded_at === null) {
                        if ($this->dispatchRetentionReminder($package, $days, $returnAfterDays, $tenantTimezone)) {
                            $reminded++;
                        }
                    }
                }
            });

        return ['reminded' => $reminded, 'returned' => $returned];
    }

    /**
     * Dispatch a retention reminder to the active residents of the package's unit.
     *
     * Returns false (no-op) when the unit has no active residents — reminded_at is left
     * null so the reminder still fires on a later run once residents are onboarded (db W).
     * When residents exist, stamps + commits reminded_at BEFORE dispatching (notif W): the
     * dedup guard is atomic w.r.t. the (deferred) notification — if saveQuietly() throws the
     * notification never fires; if the dispatch fails (caught inside notifyUsers), reminded_at
     * is already committed so no duplicate reminder on the next run. Mirrors
     * maybeNotifyArrival's stamp-then-notify order.
     *
     * The payload carries days_pending as a discrete data key (4.3.1) so FCM/webhook
     * consumers can read data.days_pending — not just parse it from the body text.
     */
    private function dispatchRetentionReminder(Package $package, int $daysPending, int $returnAfterDays, string $tenantTimezone): bool
    {
        $residentIds = $this->unitResidentUserIds($package->unit_id);

        if (empty($residentIds)) {
            return false;
        }

        // Stamp + commit the dedup guard BEFORE dispatching (notif W).
        $package->reminded_at = now();
        $package->saveQuietly();

        $carrier = $package->carrier?->name ?? $package->carrier_other ?? 'Paquete sin especificar';
        $receivedAtFormatted = Carbon::parse($package->received_at)
            ->setTimezone($tenantTimezone)
            ->format('d/m/Y');
        $daysRemaining = max(0, $returnAfterDays - $daysPending);

        $body = "Tu paquete ({$carrier}) recibido el {$receivedAtFormatted} lleva {$daysPending} día(s) en portería sin retirarse. "
            ."Será devuelto en {$daysRemaining} día(s) si no lo retiras.";

        $this->notifyUsers(
            $residentIds,
            'Recordatorio: paquete sin retirar',
            $body,
            [
                'click_action' => '/my-packages',
                'package_id' => $package->id,
                'days_pending' => $daysPending,
            ],
        );

        return true;
    }

    /**
     * Notify active unit residents if the auto-notify setting is enabled.
     * notifyUsers() defers the actual dispatch via DB::afterCommit so a
     * notification failure cannot roll back the core intake write.
     *
     * @param  array<int, string>|null  $residentUserIds  pre-resolved active residents (batch
     *                                                    path); null = resolve from the unit here
     */
    private function maybeNotifyArrival(Package $package, ?array $residentUserIds = null): void
    {
        if (! $this->getSetting('package.auto_notify_on_arrival')) {
            return;
        }

        $userIds = $residentUserIds ?? $this->unitResidentUserIds($package->unit_id);

        if (empty($userIds)) {
            return;
        }

        $this->notifyUsers(
            $userIds,
            'Paquete recibido en portería',
            'Tienes un paquete esperándote en la portería. Retíralo antes de que expire el plazo.',
            ['click_action' => '/my-packages', 'package_id' => $package->id],
        );

        // Stamp notified_at inside the transaction so it's committed atomically with the package.
        $package->notified_at = now();
        $package->saveQuietly();
    }
}
