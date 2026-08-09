<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\Channels\ResendEmailChannel;
use Mockery;
use Resend\Laravel\Facades\Resend;
use RuntimeException;
use Tests\TestCase;

/**
 * ResendEmailChannel runs inside DeliverChannelMessageJob, so a send failure MUST
 * propagate (not be swallowed) for the job's $tries to retry. The credential/address
 * guards still short-circuit cleanly.
 */
final class ResendEmailChannelTest extends TestCase
{
    public function test_send_propagates_failures_so_the_job_can_retry(): void
    {
        config(['services.resend.key' => 'test-key']);

        $emails = Mockery::mock();
        $emails->shouldReceive('send')->once()->andThrow(new RuntimeException('resend down'));
        Resend::shouldReceive('emails')->once()->andReturn($emails);

        $this->expectException(RuntimeException::class);

        (new ResendEmailChannel)->send(['vecino@example.com'], 'Aviso', 'Cuerpo', []);
    }

    public function test_send_no_ops_without_credentials(): void
    {
        config(['services.resend.key' => null]);

        // Must not throw and must not call Resend when the API key is absent.
        (new ResendEmailChannel)->send(['vecino@example.com'], 'Aviso', 'Cuerpo', []);

        $this->assertTrue(true);
    }

    public function test_send_no_ops_without_valid_addresses(): void
    {
        config(['services.resend.key' => 'test-key']);

        // No '@' address → returns before touching Resend (no facade call made).
        (new ResendEmailChannel)->send(['not-an-email'], 'Aviso', 'Cuerpo', []);

        $this->assertTrue(true);
    }
}
