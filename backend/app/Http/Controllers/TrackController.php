<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Visitas y "online". El sitio avisa cada vez que se abre una pantalla ("view") y cada minuto mientras la
 * pestaña está a la vista ("beat"). Se guarda un código anónimo por navegador: sin IP ni datos personales.
 * También devuelve cuántos avisos sin leer tiene la persona (así la campanita no necesita otro pedido).
 */
class TrackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'kind' => ['required', 'in:view,beat'],
            'path' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user('sanctum');
        $unread = $user ? NoticeController::unreadCount($user->id) : 0;
        $messages = $user && $user->hasAnyRole(['colaborador', 'superadmin']) ? MessageController::unreadCount($user->id) : 0;

        // Buscadores y bots no cuentan como visitas
        if (preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|lighthouse/i', (string) $request->userAgent())) {
            return response()->json(['unread_notices' => $unread, 'unread_messages' => $messages]);
        }

        $now = now();
        DB::table('visitors')->upsert(
            [['token' => $data['token'], 'user_id' => $user?->id, 'first_seen_at' => $now, 'last_seen_at' => $now]],
            ['token'],
            ['user_id', 'last_seen_at'],
        );

        if ($data['kind'] === 'view') {
            $visitorId = DB::table('visitors')->where('token', $data['token'])->value('id');
            $path = '/' . ltrim((string) ($data['path'] ?? '/'), '/');
            $detailId = null;
            if (preg_match('#^/detalle/([^/?\#]+)#', $path, $m)) {
                $detailId = DB::table('details')->where('slug', urldecode($m[1]))->value('id');
            }

            DB::table('page_views')->insert([
                'visitor_id' => $visitorId,
                'user_id' => $user?->id,
                'path' => mb_substr($path, 0, 255),
                'detail_id' => $detailId,
                'created_at' => $now,
            ]);
        }

        return response()->json(['unread_notices' => $unread, 'unread_messages' => $messages]);
    }
}
