<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Reembolsos
    |--------------------------------------------------------------------------
    |
    | Se reembolsa el refund_percent % si la solicitud se hace dentro de los
    | refund_window_days días corridos desde la fecha de pago de esa orden;
    | pasado ese plazo no se reembolsa nada. Cada orden pagada se evalúa por
    | separado. Los reembolsos los hace el administrador a mano (no por la API
    | de Mercado Pago ni de PayPal).
    |
    */

    'refund_window_days' => (int) env('REFUND_WINDOW_DAYS', 15),

    'refund_percent' => (int) env('REFUND_PERCENT', 100),

    // Comprobante que adjunta el admin al aprobar: tipos y tamaño máximo (KB)
    'receipt_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
    'receipt_max_kb' => 5120,

];
