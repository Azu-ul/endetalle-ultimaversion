<?php

namespace App\Notifications;

use App\Models\RefundRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Constancia para el usuario: recibimos su solicitud, con el código y los datos de la orden. */
class RefundRequestedEs extends Notification
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
        $order = $this->refund->order;
        $amount = ($order->currency === 'USD' ? 'US$ ' : '$ ') . number_format($order->amount, 2, ',', '.');
        $provider = $order->provider === 'paypal' ? 'PayPal' : 'Mercado Pago';

        return (new MailMessage)
            ->subject("Recibimos tu solicitud de reembolso {$this->refund->code}")
            ->greeting('¡Hola!')
            ->line("Registramos tu solicitud de reembolso con el código **{$this->refund->code}**.")
            ->line("Compra: plan {$order->plan->name}, {$amount}, pagada el " . $order->paid_at->locale('es')->translatedFormat('j \d\e F \d\e Y') . " con {$provider}.")
            ->line('El equipo la revisa y, si corresponde, te devuelve el dinero por el mismo medio de pago y te manda el comprobante por email. Tu plan sigue activo hasta que se apruebe el reembolso.')
            ->salutation('El equipo de ' . config('app.name'));
    }
}
