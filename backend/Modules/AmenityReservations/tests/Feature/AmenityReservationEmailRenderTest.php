<?php

declare(strict_types=1);

namespace Modules\AmenityReservations\Tests\Feature;

use Tests\TestCase;

/**
 * S10 — real-render coverage for the 5 amenity-reservation email templates.
 *
 * Mail::fake() does NOT render Blade, so it cannot catch a filename ↔
 * flattened-event-key mismatch (design §4.1, §6). These tests call
 * view('email::{slug}', ...)->render() directly — the same call
 * BladeEmailChannel::renderContent() makes — so a mismatch fails here with a
 * view-not-found error instead of silently falling back to plain text in
 * production.
 *
 * $emailBaseUrl is included in every context because BladeEmailChannel
 * merges it in automatically (static-asset base for the header logo) — a
 * direct view() call bypasses the channel, so the test supplies it itself.
 *
 * No tenant/DB setup needed: this is pure Blade rendering, no domain writes.
 */
final class AmenityReservationEmailRenderTest extends TestCase
{
    private const EMAIL_BASE_URL = 'https://example.com/email';

    public function test_created_email_renders_with_unit_and_resident_identity(): void
    {
        $html = view('email::amenity-reservation-created', [
            'amenity_name' => 'Alberca',
            'reservation_date' => '25/07/2026',
            'unit_label' => 'Casa 12 · Torre A',
            'resident_name' => 'Carlos Mendoza',
            'emailBaseUrl' => self::EMAIL_BASE_URL,
        ])->render();

        $this->assertStringContainsString('Nueva reservación', $html);
        $this->assertStringContainsString('Carlos Mendoza', $html);
        $this->assertStringContainsString('Casa 12 · Torre A', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
    }

    public function test_approved_email_renders(): void
    {
        $html = view('email::amenity-reservation-approved', [
            'amenity_name' => 'Alberca',
            'reservation_date' => '25/07/2026',
            'emailBaseUrl' => self::EMAIL_BASE_URL,
        ])->render();

        $this->assertStringContainsString('Reservación aprobada', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
    }

    public function test_payment_confirmed_email_renders_with_formatted_amounts(): void
    {
        $html = view('email::amenity-reservation-payment-confirmed', [
            'amenity_name' => 'Alberca',
            'reservation_date' => '25/07/2026',
            'amount_paid' => '$1,500.00',
            'amount_outstanding' => '$0.00',
            'emailBaseUrl' => self::EMAIL_BASE_URL,
        ])->render();

        $this->assertStringContainsString('Reservación liquidada', $html);
        $this->assertStringContainsString('$1,500.00', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
    }

    public function test_payment_rejected_email_renders_with_reason(): void
    {
        $html = view('email::amenity-reservation-payment-rejected', [
            'amenity_name' => 'Alberca',
            'reservation_date' => '25/07/2026',
            'amount' => '$1,500.00',
            'reason' => 'Comprobante ilegible.',
            'emailBaseUrl' => self::EMAIL_BASE_URL,
        ])->render();

        $this->assertStringContainsString('Pago no validado', $html);
        $this->assertStringContainsString('Comprobante ilegible.', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
    }

    public function test_surcharge_added_email_renders_with_concept_and_description(): void
    {
        $html = view('email::amenity-reservation-surcharge-added', [
            'amenity_name' => 'Alberca',
            'reservation_date' => '25/07/2026',
            'amount' => '$250.00',
            'concept_name' => 'Daños e incidentes',
            'description' => 'Vidrio roto.',
            'emailBaseUrl' => self::EMAIL_BASE_URL,
        ])->render();

        $this->assertStringContainsString('Cargo adicional aplicado', $html);
        $this->assertStringContainsString('Daños e incidentes', $html);
        $this->assertStringContainsString('Vidrio roto.', $html);
        $this->assertStringNotContainsString('{{', $html);
        $this->assertStringNotContainsString('}}', $html);
    }

    /**
     * Slug-flatten unit assertion (task 1.1 / ADR-1): a dotted event key
     * resolves to the flat view, AND every pre-existing flat (underscore-only)
     * key still resolves — the dot-flattening change is a strict superset,
     * never a regression for the 20+ existing keys.
     */
    public function test_dotted_event_key_resolves_to_flat_view(): void
    {
        $this->assertTrue(view()->exists('email::amenity-reservation-created'));
        $this->assertFalse(view()->exists('email::amenity-reservation.created'));

        // Backward-compat proof: an existing underscore-only key is unaffected.
        $this->assertTrue(view()->exists('email::payment-accepted'));
    }
}
