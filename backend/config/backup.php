<?php

return [
    // Carpeta privada (fuera de public/): los backups solo se bajan desde el panel, con sesión de superadmin
    'path' => storage_path('app/backups'),

    // Cuántos backups se conservan (los más viejos se borran al crear uno nuevo)
    'keep_auto' => (int) env('BACKUP_KEEP_AUTO', 7),
    'keep_manual' => (int) env('BACKUP_KEEP_MANUAL', 10),

    // Hora del backup automático diario (hora del servidor)
    'auto_time' => env('BACKUP_AUTO_TIME', '03:30'),

    // Qué carpetas del sitio entran en el zip (relativas a la raíz del proyecto). Nunca entran .env ni vendor.
    'include' => ['app', 'bootstrap/app.php', 'config', 'database', 'resources', 'routes', 'public', 'spa', 'storage/app'],
    'include_files' => ['artisan', 'composer.json', 'composer.lock'],
    'exclude' => ['storage/app/backups', 'public/storage'],
];
