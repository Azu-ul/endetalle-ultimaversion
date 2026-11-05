<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Services\DetailAccess;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Detalle publicado que el usuario puede abrir; si no existe, 404; si no tiene acceso, 403. */
    protected function openableDetail(Request $request, int $id): Detail
    {
        $detail = Detail::published()->findOrFail($id);

        $access = (new DetailAccess($request->user('sanctum')))->check($detail);
        abort_unless($access['allowed'], 403, 'No tenés acceso a este detalle.');

        return $detail;
    }
}
