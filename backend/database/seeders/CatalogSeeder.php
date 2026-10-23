<?php

namespace Database\Seeders;

use App\Notifications\NotificationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Modules\Appointment\Models\AppointmentStatus;
use Modules\Appointment\Models\AppointmentType;
use Modules\Appointment\Models\WellChildInterval;
use Modules\ChildRecord\Models\PopulationType;
use Modules\ChildRecord\Models\Sex;
use Modules\FollowUp\Models\FollowUpStatus;
use Modules\FollowUp\Models\FollowUpType;
use Modules\Instrument\Models\ApplicationPosition;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\ResultColor;
use Modules\Notification\Models\NotificationEvent;
use Modules\Reports\Models\ReportFrequency;
use Modules\Screening\Models\ConfirmatoryTestResult;
use Modules\Screening\Models\FindingType;
use Modules\Screening\Models\ScreeningSituation;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;
use Modules\Screening\Services\Scoring\Values\SituationKey;
use Modules\Stimulation\Models\AttendancePaymentStatus;
use Modules\Stimulation\Models\SessionSection;
use Modules\Stimulation\Models\StimulationArea;
use Modules\Tenant\Models\TenantPaymentType;

/**
 * Seeds the central fixed-enum catalogs (UUID-keyed, no tenant_id).
 *
 * These catalogs are shared across all tenants in the single-database
 * multi-tenant setup. Every row is idempotent (upsert by fixed id), so this
 * seeder is safe to call multiple times from both the central DatabaseSeeder
 * (migrate:fresh --seed) and from TenantDatabaseSeeder (runtime tenant
 * creation via TenantCreated -> tenants:seed).
 *
 * NOTE: the template seeded ~30 catalogs for the condominium domain (charges,
 * payments, housing, board, access, amenities...). Those modules are gone. The
 * EDI catalogs -- result colours, screening statuses, development areas,
 * age-group scoring rules -- are added here as their modules are built.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // TenantPaymentType (central fixed-UUID rows -- no tenant_id)
        $this->seedTenantPaymentTypes();

        // Notification event catalog (drives the per-tenant notification settings)
        $this->seedNotificationEvents();

        // ChildRecord central catalogs (D4): CURP cross-check and future
        // official exports branch on these fixed UUIDs.
        $this->seedSexes();
        $this->seedPopulationTypes();

        // Instrument support catalogs (D2/D3): NOT versioned -- vocabulary
        // shared by every instrument edition. instrument-catalog change.
        $this->seedResultColors();
        $this->seedDevelopmentAreas();
        $this->seedApplicationPositions();

        // Screening application catalogs (edi-screening-application D1):
        // lifecycle FSM, situation states, closed finding vocabulary,
        // confirmatory test outcomes.
        $this->seedScreeningStatuses();
        $this->seedScreeningSituations();
        $this->seedFindingTypes();
        $this->seedConfirmatoryTestResults();

        // Stimulation catalogs (edi-stimulation-sessions D4): official section
        // vocabulary, stimulation areas, attendance payment statuses.
        $this->seedSessionSections();
        $this->seedStimulationAreas();
        $this->seedAttendancePaymentStatuses();

        // FollowUp catalogs (edi-followup-and-referral D6): conduct type and
        // lifecycle FSM. Both are central -- the creation matrix, auto-close
        // logic, and overdue command branch on these fixed UUIDs.
        $this->seedFollowUpTypes();
        $this->seedFollowUpStatuses();
        $this->seedReportFrequencies();

        // Agenda catalogs (odd/tasks/edi-agenda.md): lifecycle FSM and
        // appointment kinds -- the lifecycle service and the anti-overlap
        // constraint branch on these fixed UUIDs.
        $this->seedAppointmentStatuses();
        $this->seedAppointmentTypes();
        $this->seedWellChildIntervals();
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * Idempotent catalog seed: INSERT ... ON CONFLICT (id) DO UPDATE.
     * No SELECT, so it is immune to the Cachable stale-lookup that made the
     * previous foreach+updateOrCreate throw a duplicate PK on re-seed (plan 040).
     * Resets deleted_at so a soft-deleted catalog row is restored; never overwrites
     * created_at on conflict.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function upsertCatalog(string $model, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $rows = array_map(static fn (array $r): array => $r + ['deleted_at' => null], $rows);
        $updateColumns = array_values(array_diff(array_keys($rows[0]), ['id', 'created_at']));
        $model::upsert($rows, ['id'], $updateColumns);
    }

    // =========================================================================
    // Tenant payment types (central, fixed UUID)
    // =========================================================================

    private function seedTenantPaymentTypes(): void
    {
        $this->upsertCatalog(TenantPaymentType::class, [
            ['id' => TenantPaymentType::CASH, 'name' => 'Efectivo', 'description' => 'Pago en efectivo'],
            ['id' => TenantPaymentType::SUBSCRIPTION, 'name' => 'Suscripción', 'description' => 'Pago por suscripción'],
            ['id' => TenantPaymentType::BANK_TRANSFER, 'name' => 'Transferencia bancaria', 'description' => 'Pago por transferencia bancaria'],
        ]);
    }

    // =========================================================================
    // ChildRecord catalogs (central, fixed UUID)
    // =========================================================================

    private function seedSexes(): void
    {
        $this->upsertCatalog(Sex::class, [
            ['id' => Sex::MALE, 'key' => 'male', 'name' => 'Masculino', 'description' => null],
            ['id' => Sex::FEMALE, 'key' => 'female', 'name' => 'Femenino', 'description' => null],
        ]);
    }

    private function seedPopulationTypes(): void
    {
        $this->upsertCatalog(PopulationType::class, [
            ['id' => PopulationType::URBAN, 'key' => 'urban', 'name' => 'Urbana', 'description' => null],
            ['id' => PopulationType::RURAL, 'key' => 'rural', 'name' => 'Rural', 'description' => null],
            ['id' => PopulationType::INDIGENOUS, 'key' => 'indigenous', 'name' => 'Indígena', 'description' => null],
        ]);
    }

    // =========================================================================
    // Instrument support catalogs (central, fixed UUID, D2/D3 -- NOT versioned)
    // =========================================================================

    private function seedResultColors(): void
    {
        $this->upsertCatalog(ResultColor::class, [
            ['id' => ResultColor::GREEN, 'key' => 'green', 'name' => 'Verde', 'sort_order' => 1],
            ['id' => ResultColor::YELLOW, 'key' => 'yellow', 'name' => 'Amarillo', 'sort_order' => 2],
            ['id' => ResultColor::RED, 'key' => 'red', 'name' => 'Rojo', 'sort_order' => 3],
        ]);
    }

    private function seedDevelopmentAreas(): void
    {
        $semaphore = DevelopmentArea::SCORING_KIND_SEMAPHORE;
        $counter = DevelopmentArea::SCORING_KIND_COUNTER;

        $this->upsertCatalog(DevelopmentArea::class, [
            ['id' => DevelopmentArea::MG, 'code' => 'MG', 'name' => 'Motriz gruesa', 'scoring_kind' => $semaphore, 'is_development_axis' => true, 'sort_order' => 1],
            ['id' => DevelopmentArea::MF, 'code' => 'MF', 'name' => 'Motriz fina', 'scoring_kind' => $semaphore, 'is_development_axis' => true, 'sort_order' => 2],
            ['id' => DevelopmentArea::LE, 'code' => 'LE', 'name' => 'Lenguaje', 'scoring_kind' => $semaphore, 'is_development_axis' => true, 'sort_order' => 3],
            ['id' => DevelopmentArea::SO, 'code' => 'SO', 'name' => 'Social', 'scoring_kind' => $semaphore, 'is_development_axis' => true, 'sort_order' => 4],
            ['id' => DevelopmentArea::CO, 'code' => 'CO', 'name' => 'Conocimiento', 'scoring_kind' => $semaphore, 'is_development_axis' => true, 'sort_order' => 5],
            ['id' => DevelopmentArea::EN, 'code' => 'EN', 'name' => 'Exploración Neurológica', 'scoring_kind' => $semaphore, 'is_development_axis' => false, 'sort_order' => 6],
            ['id' => DevelopmentArea::ALA, 'code' => 'ALA', 'name' => 'Señales de Alarma', 'scoring_kind' => $semaphore, 'is_development_axis' => false, 'sort_order' => 7],
            ['id' => DevelopmentArea::ALE, 'code' => 'ALE', 'name' => 'Señales de Alerta', 'scoring_kind' => $counter, 'is_development_axis' => false, 'sort_order' => 8],
        ]);
    }

    private function seedApplicationPositions(): void
    {
        $this->upsertCatalog(ApplicationPosition::class, [
            ['id' => ApplicationPosition::SUPINE, 'key' => 'supine', 'name' => 'Supina', 'legacy_id' => 1],
            ['id' => ApplicationPosition::PRONE, 'key' => 'prone', 'name' => 'Prona', 'legacy_id' => 2],
            ['id' => ApplicationPosition::SITTING, 'key' => 'sitting', 'name' => 'Sentado', 'legacy_id' => 3],
            ['id' => ApplicationPosition::ON_FLOOR, 'key' => 'on_floor', 'name' => 'En piso', 'legacy_id' => 4],
            ['id' => ApplicationPosition::STANDING, 'key' => 'standing', 'name' => 'De pie', 'legacy_id' => 5],
            ['id' => ApplicationPosition::NOT_APPLICABLE, 'key' => 'not_applicable', 'name' => 'No aplica', 'legacy_id' => 6],
        ]);
    }

    private function seedNotificationEvents(): void
    {
        $this->upsertCatalog(NotificationEvent::class, [
            // ── Priority 4 (the events requested by the user) ────────────────
            [
                'id' => NotificationEvent::PAYMENT_DUE_REMINDER,
                'key' => 'payment_due_reminder',
                'name' => 'Recordatorio de vencimiento',
                'description' => 'Se envía al residente (billing owner) antes de que un cargo venza.',
                'category' => NotificationCategory::COLLECTIONS,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 0,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::PAYMENT_REGISTERED,
                'key' => 'payment_registered',
                'name' => 'Pago por revisar',
                'description' => 'Se envía a roles de la mesa directiva cuando un residente registra un pago pendiente de validación.',
                'category' => NotificationCategory::COLLECTIONS,
                'audience' => 'board',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 1,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::PAYMENT_ACCEPTED,
                'key' => 'payment_accepted',
                'name' => 'Pago aceptado',
                'description' => 'Se envía al residente cuando la mesa directiva valida y acepta su pago.',
                'category' => NotificationCategory::COLLECTIONS,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 2,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::PAYMENT_REJECTED,
                'key' => 'payment_rejected',
                'name' => 'Pago rechazado',
                'description' => 'Se envía al residente cuando la mesa directiva rechaza su pago, incluyendo el motivo.',
                'category' => NotificationCategory::COLLECTIONS,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 3,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            // ── Already-firing events (completing the catalog) ────────────────
            [
                'id' => NotificationEvent::CHARGE_OVERDUE,
                'key' => 'charge_overdue',
                'name' => 'Cargo vencido',
                'description' => 'Se envía al residente cuando un cargo pasa a estado vencido (cron diario).',
                'category' => NotificationCategory::COLLECTIONS,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 4,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::CHARGE_CREATED,
                'key' => 'charge_created',
                'name' => 'Nuevo cargo emitido',
                'description' => 'Se envía al residente cuando se genera un lote de cargos que incluye su unidad.',
                'category' => NotificationCategory::COLLECTIONS,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 5,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::VISITOR_AT_GATE,
                'key' => 'visitor_at_gate',
                'name' => 'Visitante en puerta',
                'description' => 'Se envía al billing owner cuando un visitante registrado llega al acceso de la organización.',
                'category' => NotificationCategory::ACCESS,
                'audience' => 'resident',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 7,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::NEW_BOARD,
                'key' => 'new_board',
                'name' => 'Nueva mesa directiva',
                'description' => 'Se envía a los nuevos miembros de la mesa directiva cuando se abre un período.',
                'category' => NotificationCategory::SYSTEM,
                'audience' => 'board',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 8,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::PAYMENT_REGISTERED_DIRECT,
                'key' => 'payment_registered_direct',
                'name' => 'Pago registrado por administrador',
                'description' => 'Se envía al residente (billing owner) cuando el administrador registra un pago directamente en su nombre.',
                'category' => NotificationCategory::COLLECTIONS,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 10,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::TRIAL_EXPIRED,
                'key' => 'trial_expired',
                'name' => 'Prueba de módulo vencida',
                'description' => 'Se envía al administrador de la organización cuando la prueba gratuita de un módulo adicional vence y requiere ser incluida en el cobro.',
                'category' => NotificationCategory::SYSTEM,
                'audience' => 'board',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 11,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => true,
            ],
            // ── Projects ──────────────────────────────────────────────────────
            [
                'id' => NotificationEvent::PROJECT_CONTRIBUTION_REMINDER,
                'key' => 'project_contribution_reminder',
                'name' => 'Recordatorio de aportación',
                'description' => 'Se envía al residente (billing owner) cuando su unidad tiene una aportación pendiente en un proyecto voluntario.',
                'category' => NotificationCategory::PROJECTS,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => true,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 12,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            // ── Amenity Reservations (S10) ────────────────────────────────────
            // 5 of these 11 events support EMAIL (created, approved, payment_confirmed,
            // payment_rejected, surcharge_added): BladeEmailChannel flattens the dotted
            // event key into a flat Blade view slug (str_replace(['_','.'],'-') →
            // email::amenity-reservation-created), and the 5 templates ship under
            // frontend/emails/amenity-reservation-*.tsx. The remaining 6 stay
            // supports_email=false — no template shipped for them.
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_CREATED,
                'key' => 'amenity_reservation.created',
                'name' => 'Nueva reservación de amenidad',
                'description' => 'Se envía a la mesa directiva y al administrador cuando un residente crea una reservación.',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'board',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 13,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_APPROVED,
                'key' => 'amenity_reservation.approved',
                'name' => 'Reservación aprobada',
                'description' => 'Se envía al residente cuando su reservación de amenidad es aprobada.',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 14,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_REJECTED,
                'key' => 'amenity_reservation.rejected',
                'name' => 'Reservación rechazada',
                'description' => 'Se envía al residente cuando su reservación de amenidad es rechazada, incluyendo el motivo.',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 15,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_PAYMENT_REQUIRED,
                'key' => 'amenity_reservation.payment_required',
                'name' => 'Pago requerido para reservación',
                'description' => 'Se envía al residente cuando su reservación entra en pendiente de pago (al crearse o al aprobarse).',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 16,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_DEPOSIT_CONFIRMED,
                'key' => 'amenity_reservation.deposit_confirmed',
                'name' => 'Depósito de reservación confirmado',
                'description' => 'Se envía al residente cuando su pago de depósito confirma la reservación (queda saldo pendiente).',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 17,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_PAYMENT_CONFIRMED,
                'key' => 'amenity_reservation.payment_confirmed',
                'name' => 'Pago de reservación confirmado',
                'description' => 'Se envía al residente cuando un pago liquida por completo el saldo de su reservación.',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 18,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_PAYMENT_REJECTED,
                'key' => 'amenity_reservation.payment_rejected',
                'name' => 'Pago de reservación rechazado',
                'description' => 'Se envía al residente cuando el tesorero rechaza el pago que reportó para su reservación.',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 19,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_CANCELLED,
                'key' => 'amenity_reservation.cancelled',
                'name' => 'Reservación cancelada',
                'description' => 'Se envía al residente (y a la mesa directiva) cuando una reservación de amenidad se cancela.',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 20,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_SURCHARGE_ADDED,
                'key' => 'amenity_reservation.surcharge_added',
                'name' => 'Cargo adicional a reservación',
                'description' => 'Se envía al residente cuando el administrador aplica un cargo por incidente/daño a su reservación.',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => true,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push', 'blade_email']),
                'order' => 21,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_PAYMENT_REMINDER,
                'key' => 'amenity_reservation.payment_reminder',
                'name' => 'Recordatorio de pago de reservación',
                'description' => 'Se envía al residente recordándole el saldo pendiente de su reservación antes del vencimiento (cron S11).',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 22,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::AMENITY_RESERVATION_EXPIRED,
                'key' => 'amenity_reservation.expired',
                'name' => 'Reservación expirada',
                'description' => 'Se envía al residente cuando su reservación expira por falta de pago dentro del plazo (cron S11).',
                'category' => NotificationCategory::AMENITIES,
                'audience' => 'resident',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 23,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            // ── Complaint (Incidencias) ────────────────────────────────────────────
            // Push + in-app only (N6, no email channel v1). Denounced party is never
            // a recipient of any of these events (ADR-6).
            [
                'id' => NotificationEvent::COMPLAINT_CREATED,
                'key' => 'complaint_created',
                'name' => 'Nueva incidencia registrada',
                'description' => 'Se envía a los roles gestores configurados (excluyendo al vinculado a la unidad señalada) cuando se registra una incidencia.',
                'category' => NotificationCategory::COMPLAINTS,
                'audience' => 'board',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 24,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::COMPLAINT_ASSIGNED,
                'key' => 'complaint_assigned',
                'name' => 'Incidencia asignada',
                'description' => 'Se envía al usuario gestor cuando se le asigna una incidencia.',
                'category' => NotificationCategory::COMPLAINTS,
                'audience' => 'board',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 25,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::COMPLAINT_STATUS_CHANGED,
                'key' => 'complaint_status_changed',
                'name' => 'Cambio de estado de incidencia',
                'description' => 'Se envía al reportante y al gestor asignado cuando cambia el estado de una incidencia.',
                'category' => NotificationCategory::COMPLAINTS,
                'audience' => 'board',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 26,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            [
                'id' => NotificationEvent::COMPLAINT_MESSAGE,
                'key' => 'complaint_message',
                'name' => 'Nuevo mensaje en incidencia',
                'description' => 'Se envía a la otra parte del hilo (reportante o gestor) cuando se publica un mensaje de seguimiento.',
                'category' => NotificationCategory::COMPLAINTS,
                'audience' => 'board',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 27,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            // ── FollowUp (edi-followup-and-referral D8) ────────────────────────────
            // Reuses the existing engine, no new channel: log + push only (v1,
            // supports_email=false). NotificationSettingService::resolve() falls
            // back to these default_channels when a tenant has no setting row.
            [
                'id' => NotificationEvent::REFERRAL_CREATED,
                'key' => 'follow_up.referral_created',
                'name' => 'Referencia a especialista creada',
                'description' => 'Se envía a los administradores de la organización cuando se crea una referencia a especialista.',
                'category' => NotificationCategory::FOLLOW_UP,
                'audience' => 'board',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 28,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
            // ── Agenda (odd/tasks/edi-agenda.md S5) ───────────────────────────────
            [
                'id' => NotificationEvent::APPOINTMENT_ARRIVED,
                'key' => 'appointment.arrived',
                'name' => 'Paciente en espera',
                'description' => 'Se envía al profesional cuando recepción marca que su paciente llegó.',
                'category' => NotificationCategory::APPOINTMENT,
                'audience' => 'board',
                'supports_email' => false,
                'supports_push' => true,
                'supports_whatsapp' => false,
                'default_channels' => json_encode(['log', 'firebase_push']),
                'order' => 29,
                'is_active' => true,
                'is_mandatory' => false,
                'is_hidden' => false,
            ],
        ]);
    }

    // =========================================================================
    // Screening application catalogs (edi-screening-application D1)
    // =========================================================================

    private function seedScreeningStatuses(): void
    {
        $this->upsertCatalog(ScreeningStatus::class, [
            ['id' => ScreeningStatus::DRAFT, 'key' => 'draft', 'name' => 'Borrador', 'sort_order' => 1],
            ['id' => ScreeningStatus::SCORED, 'key' => 'scored', 'name' => 'Calificada', 'sort_order' => 2],
            ['id' => ScreeningStatus::COMPLETED, 'key' => 'completed', 'name' => 'Cerrada', 'sort_order' => 3],
            ['id' => ScreeningStatus::INVALIDATED, 'key' => 'invalidated', 'name' => 'Anulada', 'sort_order' => 4],
        ]);
    }

    private function seedScreeningSituations(): void
    {
        $this->upsertCatalog(ScreeningSituation::class, [
            ['id' => ScreeningSituation::FIRST_TIME_GREEN, 'key' => SituationKey::FIRST_TIME_GREEN, 'name' => 'Primera vez verde', 'sis_code' => 1, 'sort_order' => 1],
            ['id' => ScreeningSituation::FIRST_TIME_YELLOW, 'key' => SituationKey::FIRST_TIME_YELLOW, 'name' => 'Primera vez amarillo', 'sis_code' => 2, 'sort_order' => 2],
            ['id' => ScreeningSituation::FIRST_TIME_RED, 'key' => SituationKey::FIRST_TIME_RED, 'name' => 'Primera vez rojo', 'sis_code' => 3, 'sort_order' => 3],
            ['id' => ScreeningSituation::RECOVERED_FROM_YELLOW, 'key' => SituationKey::RECOVERED_FROM_YELLOW, 'name' => 'Recuperado de amarillo', 'sis_code' => 4, 'sort_order' => 4],
            ['id' => ScreeningSituation::RECOVERED_FROM_RED, 'key' => SituationKey::RECOVERED_FROM_RED, 'name' => 'Recuperado de rojo', 'sis_code' => 5, 'sort_order' => 5],
            ['id' => ScreeningSituation::UNCHANGED, 'key' => SituationKey::UNCHANGED, 'name' => 'Sin cambio', 'sis_code' => 6, 'sort_order' => 6],
            ['id' => ScreeningSituation::WORSENED, 'key' => SituationKey::WORSENED, 'name' => 'Empeoró', 'sis_code' => 6, 'sort_order' => 7],
        ]);
    }

    private function seedFindingTypes(): void
    {
        $this->upsertCatalog(FindingType::class, [
            ['id' => FindingType::AGE_GROUP_SUBSTITUTED_BY_CORRECTED_AGE, 'key' => ScoringFinding::AGE_GROUP_SUBSTITUTED_BY_CORRECTED_AGE, 'name' => 'Grupo de edad sustituido por edad corregida', 'sort_order' => 1],
            ['id' => FindingType::UNKNOWN_PERINATAL_INPUT, 'key' => ScoringFinding::UNKNOWN_PERINATAL_INPUT, 'name' => 'Dato perinatal desconocido', 'sort_order' => 2],
            ['id' => FindingType::AUTO_ANSWER_OVERRODE_MANUAL, 'key' => ScoringFinding::AUTO_ANSWER_OVERRODE_MANUAL, 'name' => 'Respuesta automática sobrescribió respuesta manual', 'sort_order' => 3],
            ['id' => FindingType::UNANSWERED_ITEM, 'key' => ScoringFinding::UNANSWERED_ITEM, 'name' => 'Reactivo sin contestar', 'sort_order' => 4],
            ['id' => FindingType::MISSING_PERCENTILE_REFERENCE, 'key' => ScoringFinding::MISSING_PERCENTILE_REFERENCE, 'name' => 'Referencia percentilar faltante', 'sort_order' => 5],
        ]);
    }

    private function seedConfirmatoryTestResults(): void
    {
        $this->upsertCatalog(ConfirmatoryTestResult::class, [
            ['id' => ConfirmatoryTestResult::DELAY_CONFIRMED, 'key' => 'delay_confirmed', 'name' => 'Retraso confirmado', 'sort_order' => 1],
            ['id' => ConfirmatoryTestResult::DELAY_RULED_OUT, 'key' => 'delay_ruled_out', 'name' => 'Retraso descartado', 'sort_order' => 2],
            ['id' => ConfirmatoryTestResult::INCONCLUSIVE, 'key' => 'inconclusive', 'name' => 'No concluyente', 'sort_order' => 3],
        ]);
    }

    // =========================================================================
    // Stimulation catalogs (central, fixed UUID, edi-stimulation-sessions D4)
    // =========================================================================

    private function seedSessionSections(): void
    {
        $this->upsertCatalog(SessionSection::class, [
            ['id' => SessionSection::INTEGRATION, 'key' => 'integration', 'name' => 'Integración', 'sort_order' => 1],
            ['id' => SessionSection::PREPARATION, 'key' => 'preparation', 'name' => 'Preparación', 'sort_order' => 2],
            ['id' => SessionSection::CORE, 'key' => 'core', 'name' => 'Actividad central', 'sort_order' => 3],
            ['id' => SessionSection::RELAXATION, 'key' => 'relaxation', 'name' => 'Relajación', 'sort_order' => 4],
        ]);
    }

    private function seedStimulationAreas(): void
    {
        $this->upsertCatalog(StimulationArea::class, [
            ['id' => StimulationArea::SENSORY, 'key' => 'sensory', 'name' => 'Sensorial', 'description' => null],
            ['id' => StimulationArea::COGNITIVE, 'key' => 'cognitive', 'name' => 'Cognitiva', 'description' => null],
            ['id' => StimulationArea::SOCIO_AFFECTIVE, 'key' => 'socio_affective', 'name' => 'Socioafectiva', 'description' => null],
            ['id' => StimulationArea::LANGUAGE, 'key' => 'language', 'name' => 'Lenguaje', 'description' => null],
            ['id' => StimulationArea::MOTOR, 'key' => 'motor', 'name' => 'Motora', 'description' => null],
        ]);
    }

    private function seedAttendancePaymentStatuses(): void
    {
        $this->upsertCatalog(AttendancePaymentStatus::class, [
            ['id' => AttendancePaymentStatus::SCHOLARSHIP, 'key' => 'scholarship', 'name' => 'Beca', 'description' => null],
            ['id' => AttendancePaymentStatus::PAID, 'key' => 'paid', 'name' => 'Pagado', 'description' => null],
            ['id' => AttendancePaymentStatus::UNPAID, 'key' => 'unpaid', 'name' => 'No pagado', 'description' => null],
        ]);
    }

    // =========================================================================
    // FollowUp catalogs (central, fixed UUID, edi-followup-and-referral D6)
    // =========================================================================

    private function seedFollowUpTypes(): void
    {
        $this->upsertCatalog(FollowUpType::class, [
            ['id' => FollowUpType::REASSESS_3_MONTHS, 'key' => 'reassess_3_months', 'name' => 'Reaplicación a 3 meses', 'sort_order' => 1],
            ['id' => FollowUpType::SPECIALIST_REFERRAL, 'key' => 'specialist_referral', 'name' => 'Referencia a especialista', 'sort_order' => 2],
            ['id' => FollowUpType::CONFIRMATORY_TEST, 'key' => 'confirmatory_test', 'name' => 'Prueba confirmatoria', 'sort_order' => 3],
        ]);
    }

    private function seedFollowUpStatuses(): void
    {
        $this->upsertCatalog(FollowUpStatus::class, [
            ['id' => FollowUpStatus::PENDING, 'key' => 'pending', 'name' => 'Pendiente', 'sort_order' => 1],
            ['id' => FollowUpStatus::IN_PROGRESS, 'key' => 'in_progress', 'name' => 'En proceso', 'sort_order' => 2],
            ['id' => FollowUpStatus::COMPLETED, 'key' => 'completed', 'name' => 'Completado', 'sort_order' => 3],
            ['id' => FollowUpStatus::OVERDUE, 'key' => 'overdue', 'name' => 'Vencido', 'sort_order' => 4],
            ['id' => FollowUpStatus::CANCELLED, 'key' => 'cancelled', 'name' => 'Cancelado', 'sort_order' => 5],
        ]);
    }

    /**
     * Cadences the scheduled-report runner branches on (REQ-RPT-013). Central and
     * fixed-UUID because the code decides "is this due tonight?" from the key.
     */
    private function seedReportFrequencies(): void
    {
        $this->upsertCatalog(ReportFrequency::class, [
            ['id' => ReportFrequency::DAILY, 'key' => 'daily', 'name' => 'Diario', 'sort_order' => 1],
            ['id' => ReportFrequency::WEEKLY, 'key' => 'weekly', 'name' => 'Semanal', 'sort_order' => 2],
            ['id' => ReportFrequency::MONTHLY, 'key' => 'monthly', 'name' => 'Mensual', 'sort_order' => 3],
        ]);
    }

    // =========================================================================
    // Agenda catalogs (central, fixed UUID, odd/tasks/edi-agenda.md)
    // =========================================================================

    private function seedAppointmentStatuses(): void
    {
        $this->upsertCatalog(AppointmentStatus::class, [
            ['id' => AppointmentStatus::SCHEDULED, 'key' => 'scheduled', 'name' => 'Agendada', 'sort_order' => 1],
            ['id' => AppointmentStatus::CONFIRMED, 'key' => 'confirmed', 'name' => 'Confirmada', 'sort_order' => 2],
            ['id' => AppointmentStatus::ARRIVED, 'key' => 'arrived', 'name' => 'En espera', 'sort_order' => 3],
            ['id' => AppointmentStatus::FULFILLED, 'key' => 'fulfilled', 'name' => 'Atendida', 'sort_order' => 4],
            ['id' => AppointmentStatus::CANCELLED, 'key' => 'cancelled', 'name' => 'Cancelada', 'sort_order' => 5],
            ['id' => AppointmentStatus::NO_SHOW, 'key' => 'no_show', 'name' => 'No asistió', 'sort_order' => 6],
        ]);
    }

    private function seedAppointmentTypes(): void
    {
        $this->upsertCatalog(AppointmentType::class, [
            ['id' => AppointmentType::SCREENING, 'key' => 'screening', 'name' => 'Prueba EDI', 'default_duration_minutes' => 30, 'sort_order' => 1],
            ['id' => AppointmentType::STIMULATION, 'key' => 'stimulation', 'name' => 'Estimulación temprana', 'default_duration_minutes' => 60, 'sort_order' => 2],
            ['id' => AppointmentType::CHECK_UP, 'key' => 'check_up', 'name' => 'Consulta de control', 'default_duration_minutes' => 20, 'sort_order' => 3],
        ]);
    }

    /**
     * Well-child recall bands from NOM-031-SSA2-1999 (8.4.2: six visits a year
     * under 1 year; 8.4.3: at least every 6 months from 1 to 4 years).
     */
    private function seedWellChildIntervals(): void
    {
        $this->upsertCatalog(WellChildInterval::class, [
            ['id' => WellChildInterval::UNDER_ONE_YEAR, 'min_age_days' => 0, 'max_age_days' => 364, 'interval_days' => 60, 'source' => 'cada 2 meses en menores de 1 año (NOM-031-SSA2-1999, 8.4.2)'],
            ['id' => WellChildInterval::ONE_TO_FOUR_YEARS, 'min_age_days' => 365, 'max_age_days' => 1824, 'interval_days' => 180, 'source' => 'cada 6 meses de 1 a 4 años (NOM-031-SSA2-1999, 8.4.3)'],
            ['id' => WellChildInterval::FIVE_YEARS_AND_OVER, 'min_age_days' => 1825, 'max_age_days' => null, 'interval_days' => 365, 'source' => 'una vez al año a partir de los 5 años'],
        ]);
    }
}
