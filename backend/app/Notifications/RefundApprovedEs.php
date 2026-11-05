<?php

namespace App\Notifications;

use App\Models\RefundRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** El reembolso se aprobó y se hizo a mano: se manda el comprobante adjunto. */
class RefundApprovedEs extends Notification
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
        $currency = $this->refund->currency === 'USD' ? 'US$ ' : '$ ';
        $mail = (new MailMessage)
            ->subject("Aprobamos tu reembolso {$this->refund->code}")
            ->greeting('¡Hola!')
            ->line("Aprobamos tu solicitud de reembolso **{$this->refund->code}** por {$currency}" . number_format($this->refund->amount, 2, ',', '.') . '.')
            ->line('Te adjuntamos el comprobante. Según el medio de pago, el dinero puede tardar unos días en verse reflejado.')
            ->line('Tu plan pasó a Free. Si querés volver a suscribirte, podés hacerlo cuando quieras desde Planes.');

        if ($this->refund->admin_note) {
            $mail->line("Nota del equipo: {$this->refund->admin_note}");
        }
        if ($this->refund->receipt_path) {
            $mail->attachFromStorageDisk('local', $this->refund->receipt_path, $this->refund->receipt_name ?? 'comprobante');
        }

        return $mail->salutation('El equipo de ' . config('app.name'));
    }
}
