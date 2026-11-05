<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Subscription;
use App\Notifications\SubscriptionEndingEs;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainSubscriptions extends Command
{
    protected $signature = 'subscriptions:maintain';

    protected $description = 'Vence los planes cumplidos, avisa los que están por vencer y cierra las órdenes sin pagar';

    private const REMINDER_DAYS = 3;
    private const PENDING_ORDER_HOURS = 48;

    public function handle(): int
    {
        // El acceso ya se corta por fecha (User::currentSubscription); acá solo se ordena el estado
        $expired = Subscription::where('status', 'active')->where('ends_at', '<=', now())->update(['status' => 'expired']);

        // Un plan menor encolado empieza cuando vence el anterior
        $activated = Subscription::where('status', 'scheduled')->where('starts_at', '<=', now())->update(['status' => 'active']);

        $reminded = 0;
        Subscription::with(['user', 'plan'])
            ->where('status', 'active')
            ->where('provider', '!=', 'manual')
            ->whereNull('reminded_at')
            ->whereNull('cancelled_at') // si canceló, no se le insiste
            ->whereNotIn('user_id', Subscription::where('status', 'scheduled')->select('user_id')) // ya pagó el plan que sigue
            ->whereBetween('ends_at', [now(), now()->addDays(self::REMINDER_DAYS)])
            ->each(function (Subscription $subscription) use (&$reminded) {
                try {
                    $subscription->user->notify(new SubscriptionEndingEs($subscription));
                    $subscription->update(['reminded_at' => now()]);
                    $reminded++;
                } catch (\Throwable $e) {
                    report($e); // si falla el correo se reintenta mañana
                }
            });

        // Un pago aprobado después igual se acredita (ver SubscriptionFulfillment::confirm)
        $orders = Order::where('status', 'pending')
            ->where('created_at', '<', now()->subHours(self::PENDING_ORDER_HOURS))
            ->update(['status' => 'expired']);

        // Visitas detalladas viejas (las estadísticas solo miran los últimos 30 días; se guardan más por las dudas)
        $keep = (int) config('cms.page_views_keep_days', 120);
        $pruned = DB::table('page_views')->where('created_at', '<', now()->subDays($keep))->delete();
        DB::table('visitors')->where('last_seen_at', '<', now()->subDays($keep))->delete();

        $this->info("Visitas viejas borradas: {$pruned}. Planes vencidos: {$expired}. Planes encolados activados: {$activated}. Avisos enviados: {$reminded}. Órdenes sin pagar cerradas: {$orders}.");

        return self::SUCCESS;
    }
}
