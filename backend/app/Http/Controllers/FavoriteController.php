<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Services\DetailAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FavoriteController extends Controller
{
    /** Ids de los detalles guardados por el usuario, del más nuevo al más viejo. */
    public function index(Request $request): JsonResponse
    {
        $ids = DB::table('favorites')
            ->where('user_id', $request->user()->id)
            ->whereIn('detail_id', Detail::published()->select('id'))
            ->orderByDesc('created_at')
            ->pluck('detail_id');

        return response()->json($ids);
    }

    /** Detalles guardados completos (como las tarjetas del explorador), del más nuevo al más viejo. */
    public function details(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = DB::table('favorites')->where('user_id', $user->id)->orderByDesc('created_at')->pluck('detail_id');
        $level = (new DetailAccess($user))->effectiveLevel();

        $details = DetailController::baseQuery()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Detail $d) => $ids->search($d->id))
            ->map(fn (Detail $d) => DetailController::card($d, $level))
            ->values();

        return response()->json($details);
    }

    // Se puede guardar cualquier detalle publicado, aunque el plan todavía no alcance para abrirlo
    public function store(Request $request, int $id): JsonResponse
    {
        $detail = Detail::published()->findOrFail($id);

        DB::table('favorites')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'detail_id' => $detail->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['favorite' => true]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        DB::table('favorites')
            ->where('user_id', $request->user()->id)
            ->where('detail_id', $id)
            ->delete();

        return response()->json(['favorite' => false]);
    }
}
