<?php

namespace Database\Seeders;

use App\Notifications\NotificationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Modules\Notification\Models\NotificationEvent;
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
                'description' => 'Se envía al billing owner cuando un visitante registrado llega al acceso del condominio.',
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
                'description' => 'Se envía al administrador del condominio cuando la prueba gratuita de un módulo adicional vence y requiere ser incluida en el cobro.',
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
        ]);
    }
}
