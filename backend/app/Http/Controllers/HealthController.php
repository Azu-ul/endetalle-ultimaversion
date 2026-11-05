<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/health: para comprobar desde el navegador que el backend responde, se conecta a la base y puede escribir
 * en storage. Solo devuelve sí/no: nada de versiones, rutas del servidor ni mensajes de error.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = true;
        } catch (\Throwable) {
            $database = false;
        }

        $storage = is_writable(storage_path('logs')) && is_writable(storage_path('framework'));
        $ok = $database && $storage;

        return response()->json([
            'status' => $ok ? 'ok' : 'error',
            'database' => $database,
            'storage' => $storage,
            'time' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }
}
