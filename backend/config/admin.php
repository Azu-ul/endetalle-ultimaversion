<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Usuario administrador (superadmin)
    |--------------------------------------------------------------------------
    |
    | Lo crea AdminUserSeeder con estos valores del .env. Nunca van escritos en
    | el código. Van por config() y no por env() directo para que sigan
    | funcionando si se cachea la configuración (php artisan config:cache).
    |
    */

    'name' => env('ADMIN_NAME', 'Administrador'),

    'email' => env('ADMIN_EMAIL'),

    'password' => env('ADMIN_PASSWORD'),

    // En producción no se acepta una contraseña más corta que esto
    'min_password_length' => 12,

];
