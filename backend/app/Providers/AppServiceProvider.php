<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // En producción los links que arma la API (verificar email, webhooks, imágenes) salen siempre con la dirección
        // pública y https de APP_URL, aunque el hosting entregue el pedido por un proxy o sin el dato de https
        if ($this->app->isProduction() && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        // Comandos para probar planes, pagos y reembolsos a mano: solo existen en el entorno local
        if ($this->app->runningInConsole() && $this->app->environment('local')) {
            $this->commands([
                \App\Dev\Commands\DevSubscription::class,
                \App\Dev\Commands\DevReset::class,
                \App\Dev\Commands\DevPayDate::class,
            ]);
        }
    }
}
