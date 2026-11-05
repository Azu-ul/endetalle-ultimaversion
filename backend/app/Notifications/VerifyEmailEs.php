<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/** Email de verificación de cuenta, en español. El enlace lo firma Laravel (ver EmailVerificationController). */
class VerifyEmailEs extends VerifyEmail
{
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Verificá tu cuenta de ' . config('app.name'))
            ->greeting('¡Hola!')
            ->line('Gracias por registrarte. Confirmá tu email para poder abrir los detalles de la biblioteca.')
            ->action('Verificar mi email', $url)
            ->line('El enlace vence en ' . config('auth.verification.expire', 60) . ' minutos. Si no creaste una cuenta, ignorá este mensaje.')
            ->salutation('El equipo de ' . config('app.name'));
    }
}
