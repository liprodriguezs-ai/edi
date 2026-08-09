<?php

namespace Modules\DataImport\Services;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;
use Modules\Collections\Services\PaymentService;
use Modules\Collections\Support\MoneyLimit;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Models\UnitUser;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\BankAccountPurpose;
use Modules\Treasury\Models\BankAccountType;
use Modules\User\Models\UserInvitation;
use Modules\User\Services\UserInvitationService;
use Modules\UserProfile\Models\UserProfile;

/**
 * RowImporter — validates and executes a single spreadsheet row.
 *
 * validate() is a pure dry-run: ZERO DB writes.
 * execute() performs all domain writes in a single DB::transaction (called by ImportRunner).
 */
class RowImporter
{
    public function __construct(private readonly ReferenceResolver $resolver) {}

    // =========================================================================
    // validate() — dry-run, zero writes
    // =========================================================================

    /**
     * Validate a single row and return any issues found.
     *
     * Mandatory fields that are missing/invalid generate severity=error.
     * Unregistered catalog values generate severity=warning.
     *
     * @param  array<string, mixed>  $row
     * @param  int  $rowNumber  1-based row number from the spreadsheet
     * @return list<array{row: int, severity: string, column: string, message: string, general?: bool}>
     */
    public function validate(array $row, int $rowNumber): array
    {
        $issues = [];

        // ── 1. Mandatory field validation ────────────────────────────────────
        $validator = Validator::make($row, [
            'tipo_unidad' => ['required', 'string', 'min:1'],
            'numero' => ['required'],
            'nombre' => ['required', 'string', 'min:1'],
            'apellido_paterno' => ['required', 'string', 'min:1'],
            'fecha_pago' => ['required', 'date'],
            // 'monto' is entered in PESOS here; it is only converted to centavos
            // (minor units) AFTER validation, at execute() time (`* 100` below).
            // MoneyLimit::MAX_MINOR_UNITS is already in centavos, so the peso-side
            // cap must divide it by 100 — using the raw constant here would allow
            // amounts up to ~$1,000,000,000 MXN, a 100× bypass of the global cap.
            'monto' => ['required', 'numeric', 'gt:0', 'max:'.(MoneyLimit::MAX_MINOR_UNITS / 100)],
        ], [
            'tipo_unidad.required' => 'El tipo de unidad es obligatorio.',
            'tipo_unidad.min' => 'El tipo de unidad no puede estar vacío.',
            'tipo_unidad.string' => 'El tipo de unidad debe ser texto.',
            'numero.required' => 'El número de unidad es obligatorio.',
            'nombre.required' => 'El nombre del residente es obligatorio.',
            'nombre.min' => 'El nombre del residente no puede estar vacío.',
            'nombre.string' => 'El nombre del residente debe ser texto.',
            'apellido_paterno.required' => 'El apellido paterno es obligatorio.',
            'apellido_paterno.min' => 'El apellido paterno no puede estar vacío.',
            'apellido_paterno.string' => 'El apellido paterno debe ser texto.',
            'fecha_pago.required' => 'La fecha de pago es obligatoria.',
            'fecha_pago.date' => 'La fecha de pago no es una fecha válida.',
            'monto.required' => 'El monto es obligatorio.',
            'monto.numeric' => 'El monto no es un número.',
            'monto.gt' => 'El monto debe ser mayor que 0.',
            'monto.max' => 'El monto no puede exceder $9,999,999.99.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $issues[] = [
                    'row' => $rowNumber,
                    'severity' => 'error',
                    'column' => $field,
                    'message' => $messages[0],
                ];
            }
        }

        // ── 2. Optional catalog warnings via ReferenceResolver ───────────────
        // Only run reference checks when ALL mandatory fields passed, so a row
        // that is already blocked (red) does not also get catalog warnings —
        // avoiding double noise on the same row.
        $mandatoryOk = empty($issues);

        if ($mandatoryOk) {
            if (! empty($row['tipo_unidad'])) {
                $resolved = $this->resolver->resolveUnitType($row['tipo_unidad'] ?? null, $rowNumber);
                array_push($issues, ...$resolved['issues']);
            }

            if (! empty($row['estado_unidad'])) {
                $resolved = $this->resolver->resolveUnitStatus($row['estado_unidad'] ?? null, $rowNumber);
                array_push($issues, ...$resolved['issues']);
            }

            if (! empty($row['torre'])) {
                $resolved = $this->resolver->resolveTower($row['torre'] ?? null, $rowNumber);
                array_push($issues, ...$resolved['issues']);
            }

            if (! empty($row['seccion'])) {
                $resolved = $this->resolver->resolveSection($row['seccion'] ?? null, $rowNumber);
                array_push($issues, ...$resolved['issues']);
            }

            if (! empty($row['metodo_pago'])) {
                $resolved = $this->resolver->resolvePaymentMethod($row['metodo_pago'] ?? null, $rowNumber);
                array_push($issues, ...$resolved['issues']);
            }

            if (! empty($row['cuenta_destino'])) {
                $resolved = $this->resolver->resolveBankAccount($row['cuenta_destino'] ?? null, $rowNumber);
                array_push($issues, ...$resolved['issues']);
            }

            if (! empty($row['relacion'])) {
                $resolved = $this->resolver->resolveUnitRelationship($row['relacion'] ?? null, $rowNumber);
                array_push($issues, ...$resolved['issues']);
            }

            // Plan 056 — surface the consent-gated cross-tenant case in the PREVIEW
            // too, so the admin sees it before executing (read-only: findCrossTenantUser
            // only SELECTs, keeping validate()'s zero-write contract).
            if (! empty($row['email'])) {
                $email = mb_strtolower(trim((string) $row['email']));
                $crossTenantUser = $this->findCrossTenantUser($email);

                if ($crossTenantUser !== null) {
                    $issues[] = $this->buildIssue($rowNumber, 'email', 'warning',
                        "El correo '{$email}' ya pertenece a una cuenta existente de otro condominio — al ejecutar la importación se enviará una invitación en vez de vincularla directamente.");
                }
            }
        }

        return $issues;
    }

    // =========================================================================
    // execute() — domain writes (called inside DB::transaction by ImportRunner)
    // =========================================================================

    /**
     * Execute a single non-blocked row: resolve/create Unit → Resident → Payment.
     *
     * MUST be called inside a DB::transaction managed by ImportRunner.
     *
     * Idempotency contract:
     *  - Unit:   find by natural key (tenant_id + type + tower + floor + number); create if not found.
     *  - User:   find by email (global, no tenant scope); fallback: (unit_id + nombre + apellido).
     *  - payment: find by physical_receipt_number (folio) first; else (unit_id + paid_at + total_amount).
     *    If payment found → SKIP ENTIRELY (no PaymentService call).
     *
     * @param  array<string, mixed>  $row
     * @param  int  $rowNumber  1-based row number.
     * @param  ReferenceResolver  $resolver  Shared memoized resolver for this run.
     */
    public function execute(array $row, int $rowNumber, ReferenceResolver $resolver): RowResult
    {
        $issues = [];

        // ── Step 1: Resolve/create Unit ──────────────────────────────────────
        [$unit, $unitCreated, $unitIssues] = $this->resolveOrCreateUnit($row, $rowNumber, $resolver);
        array_push($issues, ...$unitIssues);

        // ── Step 2: Resolve/create Resident + UnitUser ───────────────────────
        [$user, $residentCreated, $residentIssues] = $this->resolveOrCreateResident($row, $rowNumber, $unit, $resolver);
        array_push($issues, ...$residentIssues);

        // ── Step 3: Payment idempotency check + register ─────────────────────
        $totalAmountMinorUnits = (int) round((float) $row['monto'] * 100);
        $paidAt = Carbon::parse($row['fecha_pago'])->toDateString();
        $folio = $row['folio'] ?? null;

        // Check idempotency: folio (physical_receipt_number) takes priority.
        // CANCELLED payments are excluded so a re-payment after a cancellation is
        // not wrongly skipped (a cancelled payment is no longer a valid duplicate).
        if (! empty($folio)) {
            $existingPayment = Payment::where('physical_receipt_number', (string) $folio)
                ->where('tenant_id', tenant('id'))
                ->where('payment_status_id', '!=', PaymentStatus::CANCELLED)
                ->first();

            if ($existingPayment) {
                // SKIP — do NOT call PaymentService
                $issues[] = $this->buildIssue($rowNumber, 'folio', 'warning',
                    "El folio '{$folio}' ya existe — fila omitida (idempotencia).");

                return new RowResult(
                    skipped: true,
                    unitCreated: $unitCreated,
                    residentCreated: $residentCreated,
                    paymentRegistered: false,
                    unitId: $unit->id,
                    bankAccountId: null,
                    totalAmount: 0,
                    issues: $issues,
                );
            }
        }

        // Natural-key idempotency: (unit_id, paid_at, total_amount).
        // Note: two same-day same-amount payments without folio — the second one is skipped.
        // This is design-flagged behavior (plan §7.4).
        // CANCELLED payments are excluded so a re-payment after a cancellation is
        // not wrongly skipped by a stale natural-key match.
        $existingByKey = Payment::where('unit_id', $unit->id)
            ->where('paid_at', $paidAt)
            ->where('total_amount', $totalAmountMinorUnits)
            ->where('tenant_id', tenant('id'))
            ->where('payment_status_id', '!=', PaymentStatus::CANCELLED)
            ->first();

        if ($existingByKey) {
            $issues[] = $this->buildIssue($rowNumber, 'monto', 'warning',
                'Pago duplicado detectado (misma unidad, fecha y monto) — fila omitida (idempotencia).');

            return new RowResult(
                skipped: true,
                unitCreated: $unitCreated,
                residentCreated: $residentCreated,
                paymentRegistered: false,
                unitId: $unit->id,
                bankAccountId: null,
                totalAmount: 0,
                issues: $issues,
            );
        }

        // ── Resolve payment method and bank account ───────────────────────────
        $paymentMethodResolved = $resolver->resolvePaymentMethod($row['metodo_pago'] ?? null, $rowNumber);
        array_push($issues, ...$paymentMethodResolved['issues']);
        $paymentMethodId = $paymentMethodResolved['id'];

        [$bankAccountId, $bankAccountIssues] = $this->resolveOrCreateBankAccount(
            $row['cuenta_destino'] ?? null,
            $rowNumber,
            $resolver
        );
        array_push($issues, ...$bankAccountIssues);

        // ── Register payment via PaymentService (FIFO + balance + folio) ─────
        $paymentService = app(PaymentService::class);

        $paymentData = [
            'unit_id' => $unit->id,
            'paid_at' => $paidAt,
            'total_amount' => $totalAmountMinorUnits,
            'payment_method_id' => $paymentMethodId,
            'bank_account_id' => $bankAccountId,
            'payer_user_id' => $user?->id,
        ];

        if (! empty($row['referencia'])) {
            $paymentData['reference'] = (string) $row['referencia'];
        }

        if (! empty($folio)) {
            $paymentData['physical_receipt_number'] = (string) $folio;
        }

        if (! empty($row['notas'])) {
            $paymentData['notes'] = (string) $row['notas'];
        }

        // Bulk historical import: skip per-payment receipt PDF + resident notifications.
        $paymentService->register($paymentData, silent: true);

        return new RowResult(
            skipped: false,
            unitCreated: $unitCreated,
            residentCreated: $residentCreated,
            paymentRegistered: true,
            unitId: $unit->id,
            bankAccountId: $bankAccountId,
            totalAmount: $totalAmountMinorUnits,
            issues: $issues,
        );
    }

    // =========================================================================
    // Private helpers for execute()
    // =========================================================================

    /**
     * Find or create the Unit for a row.
     *
     * Natural key: (tenant_id, unit_type_id, COALESCE(tower_id,''), COALESCE(floor,''), number).
     * Unit requires unit_type_id AND unit_status_id NOT NULL.
     * If unit_type not found → auto-create (with warning).
     * If unit_status not found → use first tenant unit_status (with warning if not found).
     *
     * @return array{0: Unit, 1: bool, 2: list<array<string, mixed>>}
     */
    private function resolveOrCreateUnit(array $row, int $rowNumber, ReferenceResolver $resolver): array
    {
        $issues = [];
        $unitCreated = false;

        // Resolve or auto-create unit_type
        $typeResolved = $resolver->resolveUnitType($row['tipo_unidad'] ?? null, $rowNumber);
        $unitTypeId = $typeResolved['id'];

        if ($unitTypeId === null) {
            // Auto-create the unit type
            $typeName = trim((string) ($row['tipo_unidad'] ?? 'Sin tipo'));
            $unitType = UnitType::create([
                'name' => $typeName,
                'order' => 0,
                'is_active' => true,
                'requires_floor' => false,
            ]);
            $unitTypeId = $unitType->id;
            $resolver->setCacheEntry('unit_type:'.mb_strtolower($typeName), $unitTypeId);

            $issues[] = $this->buildIssue($rowNumber, 'tipo_unidad', 'warning',
                "El tipo de unidad '{$typeName}' no existía — se creó automáticamente.");
        }

        // Resolve optional references
        $towerResolved = $resolver->resolveTower($row['torre'] ?? null, $rowNumber);
        $towerId = $towerResolved['id'];

        $sectionResolved = $resolver->resolveSection($row['seccion'] ?? null, $rowNumber);
        $sectionId = $sectionResolved['id'];

        $floor = isset($row['piso']) && $row['piso'] !== '' ? (string) $row['piso'] : null;
        $number = (string) ($row['numero'] ?? '');

        // Find unit by natural key (query-first, matching GuardsUnitIdentity semantics)
        $unit = Unit::where('tenant_id', tenant('id'))
            ->where('unit_type_id', $unitTypeId)
            ->where(function ($q) use ($towerId) {
                if ($towerId !== null) {
                    $q->where('tower_id', $towerId);
                } else {
                    $q->whereNull('tower_id');
                }
            })
            ->where(function ($q) use ($floor) {
                if ($floor !== null) {
                    $q->where('floor', $floor);
                } else {
                    $q->whereNull('floor');
                }
            })
            ->where('number', $number)
            ->whereNull('deleted_at')
            ->first();

        if ($unit === null) {
            // Resolve unit_status (required NOT NULL for unit)
            $statusResolved = $resolver->resolveUnitStatus($row['estado_unidad'] ?? null, $rowNumber);
            $unitStatusId = $statusResolved['id'];

            if ($unitStatusId === null) {
                // Default to first tenant unit_status
                $defaultStatus = UnitStatus::where('tenant_id', tenant('id'))
                    ->orderBy('order')
                    ->orderBy('id')
                    ->first();

                $unitStatusId = $defaultStatus?->id;

                if ($unitStatusId === null) {
                    // Extreme fallback: create a minimal "Activa" status
                    $defaultStatus = UnitStatus::create([
                        'name' => 'Activa',
                        'order' => 0,
                        'is_active' => true,
                        'is_billable' => true,
                    ]);
                    $unitStatusId = $defaultStatus->id;
                    $issues[] = $this->buildIssue($rowNumber, 'estado_unidad', 'warning',
                        "No existe ningún estado de unidad — se creó 'Activa' por defecto.");
                }
            }

            $unitData = [
                'unit_type_id' => $unitTypeId,
                'number' => $number,
                'unit_status_id' => $unitStatusId,
            ];

            if ($towerId !== null) {
                $unitData['tower_id'] = $towerId;
            }

            if ($floor !== null) {
                $unitData['floor'] = $floor;
            }

            if ($sectionId !== null) {
                $unitData['section_id'] = $sectionId;
            }

            if (! empty($row['indiviso'])) {
                $unitData['ownership_share'] = (float) $row['indiviso'];
            }

            if (! empty($row['m2_construido'])) {
                $unitData['built_m2'] = (float) $row['m2_construido'];
            }

            if (! empty($row['m2_terreno'])) {
                $unitData['land_m2'] = (float) $row['m2_terreno'];
            }

            $unit = Unit::create($unitData);
            $unitCreated = true;

            $issues[] = $this->buildIssue($rowNumber, 'numero', 'warning',
                "La unidad '{$number}' no existía — se creó automáticamente.");
        }

        return [$unit, $unitCreated, $issues];
    }

    /**
     * Find or create the User (resident) and ensure the UnitUser link exists.
     *
     * Find by: email (global, no tenant scope) → fallback: (unit_id, nombre, apellido_paterno) via unit_user.
     * Guard: query-first before creating unit_user (no DB unique index on unit_user).
     *
     * @return array{0: User|null, 1: bool, 2: list<array<string, mixed>>}
     */
    private function resolveOrCreateResident(array $row, int $rowNumber, Unit $unit, ReferenceResolver $resolver): array
    {
        $issues = [];
        $residentCreated = false;

        $email = ! empty($row['email']) ? mb_strtolower(trim((string) $row['email'])) : null;
        $nombre = trim((string) ($row['nombre'] ?? ''));
        $apellidoPaterno = trim((string) ($row['apellido_paterno'] ?? ''));
        $apellidoMaterno = ! empty($row['apellido_materno']) ? trim((string) $row['apellido_materno']) : null;

        $user = null;

        if ($email !== null) {
            // Find by email globally (User is central, no tenant_id column).
            // Case-insensitive: $email is lowercased above, but a REAL account
            // created via registration or invitation-accept is stored with WHATEVER
            // casing the person typed — nothing in this codebase normalizes email
            // casing on store (verified: no mutator on the User model, no citext,
            // AuthController::register() stores $validated['email'] verbatim). An
            // exact-match lookup would silently miss a real resident's account over
            // a casing difference — duplicating it, and for the consent-gated branch
            // (plan 056) letting the cross-tenant check be bypassed by a casing
            // mismatch instead of correctly detecting the existing account.
            $user = User::withoutGlobalScope(UserTenantScope::class)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();
        }

        if ($user === null) {
            // Fallback: search by (unit + nombre + apellido_paterno) via unit_user pivot
            $linkedUserIds = UnitUser::where('unit_id', $unit->id)
                ->where('tenant_id', tenant('id'))
                ->pluck('user_id')
                ->all();

            if (! empty($linkedUserIds)) {
                $user = User::withoutGlobalScope(UserTenantScope::class)
                    ->where('name', $nombre)
                    ->where('last_name', $apellidoPaterno)
                    ->whereIn('id', $linkedUserIds)
                    ->first();
            }
        }

        // Resolved up front: needed for the unit_user link below AND as metadata on
        // the invitation in the consent-gated branch (plan 056).
        $relationResolved = $resolver->resolveUnitRelationship($row['relacion'] ?? null, $rowNumber);
        $unitRelationshipId = $relationResolved['id'];

        if ($user === null) {
            // Create the user
            $userData = [
                'name' => $nombre,
                'last_name' => $apellidoPaterno,
                'auth_active' => false, // import-created users are not active until they set a password
            ];

            if ($apellidoMaterno !== null) {
                $userData['second_last_name'] = $apellidoMaterno;
            }

            if ($email !== null) {
                $userData['email'] = $email;
            }

            if (! empty($row['telefono'])) {
                $userData['phone'] = (string) $row['telefono'];
            }

            $user = User::create($userData);

            // Attach to tenant
            $user->tenants()->attach(tenant('id'));

            // Create UserProfile with optional PII (idempotent on user_id)
            $profileData = [];

            if (! empty($row['rfc'])) {
                $profileData['rfc'] = (string) $row['rfc'];
            }

            if (! empty($row['curp'])) {
                $profileData['curp'] = (string) $row['curp'];
            }

            if (! empty($row['fecha_nacimiento'])) {
                try {
                    $profileData['birthdate'] = Carbon::parse($row['fecha_nacimiento'])->toDateString();
                } catch (\Throwable) {
                    // Silently skip malformed birthdate
                }
            }

            if (! empty($profileData)) {
                UserProfile::firstOrCreate(['user_id' => $user->id], $profileData);
            }

            $residentCreated = true;

            $issues[] = $this->buildIssue($rowNumber, 'nombre', 'warning',
                "El residente '{$nombre} {$apellidoPaterno}' no existía — se creó automáticamente.");
        } else {
            $isTenantMember = $user->tenants()->where('tenant.id', tenant('id'))->exists();

            if (! $isTenantMember) {
                // Plan 056 — consent-gated cross-tenant enrollment guard: this email
                // belongs to a REAL existing account that never joined this tenant.
                // Silently attach()-ing it would let any admin holding
                // create.data_import unilaterally enroll a stranger's account into
                // their cluster. Route through the invitation engine instead and stop
                // here: no tenant attach, no unit_user link, until the person accepts.
                array_push($issues, ...$this->inviteCrossTenantUser($user, $unit, $unitRelationshipId, $rowNumber));

                return [null, false, $issues];
            }
            // Already a member of this tenant — idempotent no-op (unchanged).
        }

        // Ensure unit_user link exists (query-first guard — no DB unique index).
        // Only reached for a freshly created user or an already-consenting tenant
        // member; the non-consented cross-tenant branch above returns earlier.
        $existingLink = UnitUser::where('tenant_id', tenant('id'))
            ->where('unit_id', $unit->id)
            ->where('user_id', $user->id)
            ->where('unit_relationship_id', $unitRelationshipId)
            ->first();

        if ($existingLink === null) {
            UnitUser::create([
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'unit_relationship_id' => $unitRelationshipId,
                'is_billing_owner' => ! empty($row['titular_de_pago']),
                'is_legal_representative' => false,
            ]);
        }

        return [$user, $residentCreated, $issues];
    }

    /**
     * Detect whether an email belongs to an EXISTING global user who is NOT yet a
     * member of the current tenant (the consent-gated case — plan 056). Read-only:
     * only SELECTs, so it is safe to call from validate()'s zero-write dry run as
     * well as from execute().
     *
     * Returns null both when no account exists for this email, and when the account
     * is already a member of this tenant (the normal, non-blocking case).
     */
    private function findCrossTenantUser(string $email): ?User
    {
        // Case-insensitive for the same reason as the lookup in
        // resolveOrCreateResident() above — see that comment.
        $user = User::withoutGlobalScope(UserTenantScope::class)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($user === null) {
            return null;
        }

        $isTenantMember = $user->tenants()->where('tenant.id', tenant('id'))->exists();

        return $isTenantMember ? null : $user;
    }

    /**
     * Plan 056 — consent-gated cross-tenant enrollment guard. Reuses
     * UserInvitationService::createAndSend(), the same central invitation factory
     * the resident-invitation UI uses for Unit-targeted invites. Deliberately NOT
     * ResidentInvitationService::inviteToUnit(): its INVITABLE_RELATIONSHIPS guard
     * excludes OWNER ("becoming the owner goes through UnitUserService::changeOwner"),
     * but ReferenceResolver::resolveUnitRelationship() defaults a blank `relacion`
     * column to OWNER — the common case for a bulk historical import — so that guard
     * would reject the row outright. createAndSend() has no such restriction and
     * already dedups against any still-valid pending invitation for this
     * email+unit+tenant (rotates/resends instead of creating a duplicate on a
     * re-imported file).
     *
     * @return list<array{row: int, severity: string, column: string, message: string}>
     */
    private function inviteCrossTenantUser(User $user, Unit $unit, ?string $unitRelationshipId, int $rowNumber): array
    {
        $email = (string) $user->email;

        app(UserInvitationService::class)->createAndSend($unit, $email, [
            'unit_relationship_id' => $unitRelationshipId,
            'mail_context' => [
                'unit_label' => $unit->full_identifier,
                'cluster_name' => tenant()?->name,
                'ttl_days' => UserInvitation::ttlDays(),
            ],
        ]);

        return [$this->buildIssue($rowNumber, 'email', 'warning',
            "El correo '{$email}' ya pertenece a una cuenta existente en otro condominio — invitación enviada. Se vinculará a la unidad cuando la acepte.")];
    }

    /**
     * Resolve or auto-create a bank account by name.
     *
     * If cuenta_destino is blank → use first active bank account for tenant.
     * If not found → auto-create with type=Caja, purpose=first tenant BankAccountPurpose.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function resolveOrCreateBankAccount(?string $name, int $rowNumber, ReferenceResolver $resolver): array
    {
        $issues = [];

        if (empty($name)) {
            // Use first bank account for tenant as fallback
            $account = BankAccount::where('tenant_id', tenant('id'))
                ->orderBy('created_at')
                ->first();

            if ($account) {
                return [$account->id, $issues];
            }

            // No accounts at all — auto-create a Caja
            $name = 'Caja';
        }

        // Try to find by name (via resolver, memoized)
        $resolved = $resolver->resolveBankAccount($name, $rowNumber);

        if ($resolved['id'] !== null) {
            return [$resolved['id'], $issues];
        }

        // Auto-create with BankAccountType::CASH (Caja)
        $defaultPurpose = BankAccountPurpose::where('tenant_id', tenant('id'))
            ->orderBy('order')
            ->orderBy('id')
            ->first();

        $accountData = [
            'name' => $name,
            'bank_account_type_id' => BankAccountType::CASH,
            'currency' => 'MXN',
            'initial_balance' => 0,
            'is_active' => true,
            'is_primary' => false,
        ];

        if ($defaultPurpose !== null) {
            $accountData['bank_account_purpose_id'] = $defaultPurpose->id;
        }

        $account = BankAccount::create($accountData);

        // Update resolver cache so subsequent rows reuse the same account
        $resolver->setCacheEntry('bank_account:'.mb_strtolower(trim($name)), $account->id);

        $issues[] = $this->buildIssue($rowNumber, 'cuenta_destino', 'warning',
            "La cuenta '{$name}' no existía — se creó automáticamente (tipo Caja).");

        return [$account->id, $issues];
    }

    /**
     * Build a single Issue array.
     *
     * @return array{row: int, severity: string, column: string, message: string}
     */
    private function buildIssue(int $row, string $column, string $severity, string $message): array
    {
        return [
            'row' => $row,
            'severity' => $severity,
            'column' => $column,
            'message' => $message,
        ];
    }
}
