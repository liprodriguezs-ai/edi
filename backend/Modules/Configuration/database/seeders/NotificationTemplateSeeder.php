<?php

namespace Modules\Configuration\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Setting\Models\Setting;

/**
 * NotificationTemplateSeeder — Module 12 (§16.3.5).
 *
 * Seeds the default notification templates for the CURRENT tenant. Each template
 * is a Setting row under `notification_template.{event}` whose value is JSON:
 *   { "title": "...", "body": "...{var}...", "is_active": true }
 *
 * Idempotent (updateOrCreate by slug). Must run inside tenant context so the
 * BelongsToTenant trait stamps tenant_id. Variables in the body use {snake_case}
 * tokens substituted at dispatch time by TemplateRenderer.
 */
class NotificationTemplateSeeder extends Seeder
{
    /**
     * @return array<string, array{title: string, body: string}>
     */
    public static function defaults(): array
    {
        return [
            'notification_template.charge_created' => [
                'title' => 'Nuevo cargo: {concept_name}',
                'body' => 'Se generó un nuevo cargo: {concept_name}. Revisa el detalle en tu estado de cuenta. Vence el {due_date}.',
            ],
            // Plural copy variant of charge_created, used ONLY by the consolidated
            // catch-up dispatch (one notification per unit covering N periods generated
            // in a single cron run). It is NOT a separate notification_event: the
            // charge_created event still governs is_active and channels. Its tokens
            // differ from the singular template on purpose — a catch-up has a RANGE of
            // due dates, not one {due_date}.
            'notification_template.charge_created_batch' => [
                'title' => 'Nuevos cargos: {concept_name}',
                'body' => 'Se generaron {charge_count} cargos de {concept_name} correspondientes a varios periodos. Vencen entre el {first_due_date} y el {last_due_date}. Revisa el detalle en tu estado de cuenta.',
            ],
            'notification_template.charge_overdue' => [
                'title' => 'Cargos vencidos',
                'body' => 'Tienes {count} cargo(s) vencido(s). Regulariza tu pago para evitar recargos.',
            ],
            // board-facing: resident submitted a payment pending validation
            'notification_template.payment_registered' => [
                'title' => 'Pago por validar',
                'body' => 'Residente {resident_name} ({unit_label}) registró un pago de {amount} ({method}) el {paid_at}. Revísalo en el portal.',
            ],
            // resident-facing: admin registered a direct payment on resident's behalf
            'notification_template.payment_registered_direct' => [
                'title' => 'Pago registrado',
                'body' => 'Hola {resident_name}, tu administrador registró un pago de {amount} (folio {receipt_number}) el {paid_at}. Revísalo en el portal.',
            ],
            'notification_template.payment_accepted' => [
                'title' => 'Pago confirmado',
                'body' => 'Hola {resident_name}, tu pago de {amount} fue validado (folio {receipt_number}). ¡Gracias!',
            ],
            'notification_template.payment_rejected' => [
                'title' => 'Pago rechazado',
                'body' => 'Hola {resident_name}, tu pago de {amount} no pudo ser validado: {reason} Puedes subir un comprobante corregido.',
            ],
            'notification_template.payment_due_reminder' => [
                'title' => 'Recordatorio de pago',
                'body' => 'Hola {resident_name}, tu cargo "{concept_name}" de {amount} vence el {due_date}. Días restantes: {days_left}.',
            ],
            'notification_template.visitor_at_gate' => [
                'title' => 'Visita en puerta',
                'body' => 'El visitante {visitor_name} ha ingresado a tu unidad.',
            ],
            'notification_template.new_board' => [
                'title' => 'Nueva mesa directiva',
                'body' => 'Se conformó una nueva mesa directiva en {cluster_name}. Periodo: {period}.',
            ],
            'notification_template.trial_expired' => [
                'title' => 'Prueba de {module} vencida',
                'body' => 'La prueba de {module} venció. Contrata el plan del condominio para no perder el acceso.',
            ],
            'notification_template.project_contribution_reminder' => [
                'title' => 'Recordatorio de aportación',
                'body' => 'Hola {resident_name}, el proyecto "{project_title}" sigue abierto y tu unidad aún no aporta. Fecha límite: {due_date}.',
            ],
            // ── Amenity Reservations (S10) ────────────────────────────────────
            'notification_template.amenity_reservation.created' => [
                'title' => 'Nueva reservación de amenidad',
                'body' => 'Se creó una nueva reservación de {amenity_name} para el {reservation_date}.',
            ],
            'notification_template.amenity_reservation.approved' => [
                'title' => 'Reservación aprobada',
                'body' => 'Tu reservación de {amenity_name} para el {reservation_date} fue aprobada.',
            ],
            'notification_template.amenity_reservation.rejected' => [
                'title' => 'Reservación rechazada',
                'body' => 'Tu reservación de {amenity_name} para el {reservation_date} fue rechazada: {reason}',
            ],
            'notification_template.amenity_reservation.payment_required' => [
                'title' => 'Pago requerido para tu reservación',
                'body' => 'Tu reservación de {amenity_name} para el {reservation_date} requiere un pago de {amount} antes del {deadline}.',
            ],
            'notification_template.amenity_reservation.deposit_confirmed' => [
                'title' => 'Depósito confirmado',
                'body' => 'Tu depósito para {amenity_name} ({reservation_date}) fue confirmado. Saldo pendiente: {amount_outstanding}.',
            ],
            // Title deliberately distinct from Collections' generic 'payment_accepted'
            // ("Pago confirmado") — every earmark payment validation also fires that
            // event, so this one must read differently or residents see two
            // identical-looking rows for the same payment.
            'notification_template.amenity_reservation.payment_confirmed' => [
                'title' => 'Reservación liquidada',
                'body' => 'Tu pago para {amenity_name} ({reservation_date}) fue confirmado. Tu reservación está completamente liquidada.',
            ],
            'notification_template.amenity_reservation.payment_rejected' => [
                'title' => 'Pago de reservación rechazado',
                'body' => 'Tu pago de {amount} para la reservación de {amenity_name} fue rechazado: {reason}',
            ],
            'notification_template.amenity_reservation.cancelled' => [
                'title' => 'Reservación cancelada',
                'body' => 'Tu reservación de {amenity_name} para el {reservation_date} fue cancelada. Motivo: {reason}',
            ],
            'notification_template.amenity_reservation.surcharge_added' => [
                'title' => 'Cargo adicional aplicado',
                'body' => 'Se aplicó un cargo adicional de {amount} a tu reservación de {amenity_name} ({concept_name}).',
            ],
            'notification_template.amenity_reservation.payment_reminder' => [
                'title' => 'Recordatorio de pago de reservación',
                'body' => 'Tu reservación de {amenity_name} para el {reservation_date} tiene un saldo pendiente de {amount_outstanding}, vence el {deadline}.',
            ],
            'notification_template.amenity_reservation.expired' => [
                'title' => 'Reservación expirada',
                'body' => 'Tu reservación de {amenity_name} para el {reservation_date} expiró por falta de pago.',
            ],
        ];
    }

    public function run(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach (self::defaults() as $slug => $template) {
            Setting::putValue(
                $slug,
                [
                    'title' => $template['title'],
                    'body' => $template['body'],
                    'is_active' => true,
                ],
                Setting::TYPE_JSON
            );
        }
    }
}
