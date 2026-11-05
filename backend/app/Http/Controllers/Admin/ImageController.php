<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Images\DetailImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pasa las imágenes cargadas antes de la v2 a la protección y optimización nuevas, de a pocas por vez. */
class ImageController extends Controller
{
    public function status(): JsonResponse
    {
        return response()->json(['pending' => DetailImages::pendingCount()]);
    }

    public function optimize(Request $request): JsonResponse
    {
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');
        $limit = min(max((int) $request->input('limit', 4), 1), 10);

        return response()->json(DetailImages::migrateLegacy($limit));
    }
}
