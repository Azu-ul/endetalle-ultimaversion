<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso al usuario de que el pago se acreditó y el plan quedó activo. */
class PaymentConfirmedEs extends Notification
{
    public function __construct(private Order $order)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $ends = $this->order->subscription?->ends_at?->locale('es')->translatedFormat('j \d\e F \d\e Y');
        $amount = ($this->order->currency === 'USD' ? 'US$ ' : '$ ') . number_format($this->order->amount, 2, ',', '.');

        return (new MailMessage)
            ->subject('Recibimos tu pago: plan ' . $this->order->plan->name . ' activo')
            ->greeting('¡Gracias por tu pago!')
            ->line("Se acreditó {$amount} y tu plan {$this->order->plan->name} ya está activo" . ($ends ? " hasta el {$ends}." : '.'))
            ->action('Ir a mi cuenta', rtrim(config('app.frontend_url'), '/') . '/mi-cuenta')
            ->salutation('El equipo de ' . config('app.name'));
    }
}
