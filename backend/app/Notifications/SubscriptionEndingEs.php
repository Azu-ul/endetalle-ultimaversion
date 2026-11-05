<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso unos días antes de que venza el plan (los planes se pagan por período, no se renuevan solos). */
class SubscriptionEndingEs extends Notification
{
    public function __construct(private Subscription $subscription)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $ends = $this->subscription->ends_at->locale('es')->translatedFormat('j \d\e F');

        return (new MailMessage)
            ->subject('Tu plan ' . $this->subscription->plan->name . ' vence pronto')
            ->greeting('¡Hola!')
            ->line("Tu plan {$this->subscription->plan->name} vence el {$ends}. Después vuelve al plan Free.")
            ->line('Si querés seguir descargando archivos y viendo todo el catálogo, renovalo antes de esa fecha: los meses se suman al final de tu plan actual.')
            ->action('Renovar mi plan', rtrim(config('app.frontend_url'), '/') . '/planes')
            ->salutation('El equipo de ' . config('app.name'));
    }
}
