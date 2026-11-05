<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Vence planes, avisa los que están por vencer y cierra órdenes sin pagar (cron: php artisan schedule:run cada minuto)
Schedule::command('subscriptions:maintain')->dailyAt('03:00');

// Backup automático diario (base de datos + archivos). Se conservan los últimos config('backup.keep_auto').
Schedule::command('backup:run')->dailyAt(config('backup.auto_time', '03:30'));
