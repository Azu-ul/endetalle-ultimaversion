<?php

return [
    // Versión del CMS que se muestra en el dashboard. Se sube a mano en cada actualización importante.
    'version' => env('CMS_VERSION', '2.1.0'),
    'released_at' => env('CMS_RELEASED_AT', '2026-11-03'),

    // Zona horaria con la que se muestran y se agrupan fechas para el administrador ("hoy", exportaciones)
    'display_timezone' => env('CMS_TIMEZONE', 'America/Argentina/Buenos_Aires'),

    // Imágenes ilustradas: lado más largo y peso máximo (bytes) de cada variante
    'images' => [
        'original_max_edge' => 2400,
        'original_max_bytes' => 2 * 1024 * 1024,   // los detalles ilustrados no pasan de 2 MB
        'preview_max_edge' => 1500,
        'preview_max_bytes' => 600 * 1024,
        'thumb_max_edge' => 900,                   // portada que se ve en las tarjetas (pública)
        'thumb_max_bytes' => 220 * 1024,
        'banner_max_edge' => 1800,
        'banner_max_bytes' => 400 * 1024,
        'input_max_kb' => 12288,                   // lo que se acepta subir; después se reduce
        'max_pixels' => 30_000_000,
    ],

    // Cuántos días se guardan las visitas detalladas (las estadísticas viejas se borran en el mantenimiento diario)
    'page_views_keep_days' => 120,
];
