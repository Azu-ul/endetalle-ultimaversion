<?php

namespace App\Notifications;

use App\Models\RefundRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** El reembolso no se aprobó: se explica el motivo. El plan no se toca. */
class RefundRejectedEs extends Notification
{
    public function __construct(private RefundRequest $refund)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Sobre tu solicitud de reembolso {$this->refund->code}")
            ->greeting('¡Hola!')
            ->line("Revisamos tu solicitud de reembolso **{$this->refund->code}** y no pudimos aprobarla.")
            ->line("Motivo: {$this->refund->rejection_reason}")
            ->line('Tu plan sigue activo. Si tenés dudas, escribinos desde la sección Contacto.')
            ->salutation('El equipo de ' . config('app.name'));
    }
}
