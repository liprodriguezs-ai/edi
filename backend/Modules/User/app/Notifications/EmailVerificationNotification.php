<?php

// namespace App\Notifications;

namespace Modules\User\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Models\Notification;

class EmailVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private $verificationCode;

    public function __construct($verificationCode)
    {
        $this->verificationCode = $verificationCode;
    }

    public function via($notifiable)
    {
        // return ['mail'];
        return ['mail', 'log'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Verifica tu correo electrónico')
            ->line('Por favor verifica tu correo electrónico usando el siguiente código:')
            ->line($this->verificationCode)
            ->action('Verificar Email', url('/'))// se agg
            ->line('Este código expirará en 60 minutos.')
            ->line('Si no has solicitado este código, puedes ignorar este mensaje.');
    }

    public function toLog($notifiable)// se agg
    {
        // Escribe el código de verificación en el log
        Log::info('Código de verificación enviado para el usuario '.$notifiable->email.': '.$this->verificationCode);
    }

    // se agg
    public function toArray($notifiable)
    {
        return [
            'verification_code' => $this->verificationCode,
        ];
    }
}
