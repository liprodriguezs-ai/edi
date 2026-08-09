<?php

namespace Modules\Email\Emails;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResidentInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  array{inviter_name?: string|null, unit_label?: string|null, cluster_name?: string|null, relationship_name?: string|null, ttl_days?: int|null}  $context
     */
    public function __construct(
        public string $acceptUrl,
        public array $context = [],
    ) {}

    public function build(): self
    {
        $emailBaseUrl = config('email.assets_url')
            ?? rtrim(config('app.url'), '/').'/email';

        return $this->subject('Te invitaron a tu unidad')
            ->view('email::resident-invitation')
            ->with([
                'acceptUrl' => $this->acceptUrl,
                'emailBaseUrl' => $emailBaseUrl,
                // Non-null fallbacks: the compiled Blade interpolates these directly
                // (the React Email flow has no @if), so empty values would leave gaps.
                'inviterName' => $this->context['inviter_name'] ?? 'Un residente',
                'unitLabel' => $this->context['unit_label'] ?? 'tu unidad',
                'clusterName' => $this->context['cluster_name'] ?? 'tu condominio',
                'relationshipName' => $this->context['relationship_name'] ?? 'residente',
                'ttlDays' => $this->context['ttl_days'] ?? 7,
            ]);
    }
}
