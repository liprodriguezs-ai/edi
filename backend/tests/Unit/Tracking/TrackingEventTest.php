<?php

declare(strict_types=1);

namespace Tests\Unit\Tracking;

use Modules\Tracking\DTO\TrackingEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the TrackingEvent value object: defaulting (fromArray) and
 * the PII hashing contract every channel relies on.
 */
final class TrackingEventTest extends TestCase
{
    public function test_from_array_generates_event_id_and_defaults_when_absent(): void
    {
        $event = TrackingEvent::fromArray(['name' => 'Lead']);

        $this->assertSame('Lead', $event->name);
        $this->assertNotEmpty($event->eventId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $event->eventId,
        );
        $this->assertSame('website', $event->actionSource);
        $this->assertSame([], $event->params);
        $this->assertSame([], $event->userData);
    }

    public function test_from_array_preserves_explicit_values(): void
    {
        $event = TrackingEvent::fromArray([
            'name' => 'Purchase',
            'event_id' => 'evt-123',
            'event_time' => 1700000000,
            'source_url' => 'https://edi.example.com/pricing',
            'action_source' => 'system_generated',
            'params' => ['value' => 199.0, 'currency' => 'MXN'],
            'user_data' => ['email' => 'a@b.com'],
        ]);

        $this->assertSame('evt-123', $event->eventId);
        $this->assertSame(1700000000, $event->eventTime);
        $this->assertSame('https://edi.example.com/pricing', $event->sourceUrl);
        $this->assertSame('system_generated', $event->actionSource);
        $this->assertSame(['value' => 199.0, 'currency' => 'MXN'], $event->params);
    }

    public function test_hash_pii_normalizes_case_and_whitespace_before_hashing(): void
    {
        $expected = hash('sha256', 'juan@example.com');

        $this->assertSame($expected, TrackingEvent::hashPii('  Juan@Example.com  '));
        $this->assertSame($expected, TrackingEvent::hashPii('juan@example.com'));
    }

    public function test_hashed_user_data_hashes_only_pii_keys_and_drops_empties(): void
    {
        $event = TrackingEvent::fromArray([
            'name' => 'Purchase',
            'user_data' => [
                'email' => 'Juan@Example.com',
                'phone' => '+525512345678',
                'external_id' => 'user-uuid-1',
                'fbp' => 'fb.1.111.222',
                'fbc' => 'fb.1.111.333',
                'gclid' => 'gclid-abc',
                'client_id' => 'ga-client-1',
                'client_ip' => '127.0.0.1',
                'client_user_agent' => 'jest-agent',
            ],
        ]);

        $hashed = $event->hashedUserData();

        $this->assertSame(hash('sha256', 'juan@example.com'), $hashed['email']);
        $this->assertSame(hash('sha256', '+525512345678'), $hashed['phone']);
        $this->assertSame(hash('sha256', 'user-uuid-1'), $hashed['external_id']);

        // Non-PII identifiers pass through UNCHANGED.
        $this->assertSame('fb.1.111.222', $hashed['fbp']);
        $this->assertSame('fb.1.111.333', $hashed['fbc']);
        $this->assertSame('gclid-abc', $hashed['gclid']);
        $this->assertSame('ga-client-1', $hashed['client_id']);
        $this->assertSame('127.0.0.1', $hashed['client_ip']);
        $this->assertSame('jest-agent', $hashed['client_user_agent']);
    }

    public function test_hashed_user_data_drops_missing_pii_keys(): void
    {
        $event = TrackingEvent::fromArray([
            'name' => 'Lead',
            'user_data' => ['fbp' => 'fb.1.111.222'],
        ]);

        $hashed = $event->hashedUserData();

        $this->assertArrayNotHasKey('email', $hashed);
        $this->assertArrayNotHasKey('phone', $hashed);
        $this->assertArrayNotHasKey('external_id', $hashed);
        $this->assertSame('fb.1.111.222', $hashed['fbp']);
    }
}
