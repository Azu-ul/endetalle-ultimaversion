<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Archivos descargables de los detalles (DWG y SKP)
    |--------------------------------------------------------------------------
    |
    | Se guardan siempre en un disco PRIVADO (nunca en public) y salen solo por la ruta
    | autenticada /api/details/{id}/files/{type}. Hoy es 'local' (storage/app/private);
    | para pasar a un almacenamiento S3 más adelante alcanza con definir un disco S3 y
    | cambiar DETAILS_DISK. DETAILS_MAX_MB es el tope por archivo que valida la subida.
    |
    */

    'details_disk' => env('DETAILS_DISK', 'local'),

    'details_max_mb' => (int) env('DETAILS_MAX_MB', 15),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false, // privado: no se expone ninguna ruta para leerlo desde afuera
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            // Se guarda directo en public/storage (sin storage:link): el hosting bloquea los symlinks
            'root' => public_path('storage'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    // Sin symlinks (storage:link): el hosting los bloquea. El disco "public" escribe directo en public/storage.
    'links' => [],

];
