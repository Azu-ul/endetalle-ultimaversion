<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Avisos que el administrador le manda al usuario (campanita). Cada usuario ve solo los suyos. */
class NoticeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $rows = DB::table('user_notices')
            ->join('admin_notices', 'admin_notices.id', '=', 'user_notices.notice_id')
            ->where('user_notices.user_id', $userId)
            ->orderByDesc('admin_notices.created_at')
            ->orderByDesc('user_notices.id')
            ->limit(60)
            ->get(['user_notices.id', 'user_notices.read_at', 'admin_notices.title', 'admin_notices.body', 'admin_notices.link', 'admin_notices.kind', 'admin_notices.created_at']);

        return response()->json([
            'unread_count' => self::unreadCount($userId),
            'notices' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'title' => $r->title,
                'body' => $r->body,
                'link' => $r->link,
                'kind' => $r->kind,
                'read' => $r->read_at !== null,
                'created_at' => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        DB::table('user_notices')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['unread_count' => self::unreadCount($request->user()->id)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        DB::table('user_notices')
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::table('user_notices')->where('id', $id)->where('user_id', $request->user()->id)->delete();

        return response()->json(['unread_count' => self::unreadCount($request->user()->id)]);
    }

    public static function unreadCount(int $userId): int
    {
        return DB::table('user_notices')->where('user_id', $userId)->whereNull('read_at')->count();
    }
}
