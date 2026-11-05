<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// El frontend (React Router) maneja todas las rutas del sitio; Laravel solo
// sirve su index.html con las metaetiquetas para compartir en redes.
Route::get('/{path?}', SpaController::class)
    ->where('path', '(?!(api|sanctum|storage)(/|$)|up$).*');
