<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/** Email para restablecer la contraseña, en español. Lleva al formulario del sitio (/restablecer). */
class ResetPasswordEs extends ResetPassword
{
    protected function resetUrl($notifiable): string
    {
        return rtrim(config('app.frontend_url'), '/') . '/restablecer?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire');

        return (new MailMessage)
            ->subject('Restablecer tu contraseña de ' . config('app.name'))
            ->greeting('¡Hola!')
            ->line('Recibimos un pedido para restablecer la contraseña de tu cuenta.')
            ->action('Elegir una contraseña nueva', $this->resetUrl($notifiable))
            ->line("El enlace vence en {$minutes} minutos. Si no lo pediste vos, ignorá este mensaje: tu contraseña no cambia.")
            ->salutation('El equipo de ' . config('app.name'));
    }
}
