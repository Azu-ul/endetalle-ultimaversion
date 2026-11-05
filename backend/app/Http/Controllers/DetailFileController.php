<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use App\Models\DetailFile;
use App\Services\DetailAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Descarga de los archivos DWG y SKP según el plan del usuario (DetailAccess::canDownload). */
class DetailFileController extends Controller
{
    public function download(Request $request, int $id, string $type): StreamedResponse
    {
        $detail = Detail::published()->with('requiredPlan:id,slug,name,level')->findOrFail($id);
        $access = new DetailAccess($request->user());

        // Mismas reglas que para abrir el detalle: plan requerido y cupo
        abort_unless($access->check($detail)['allowed'], 403, 'Tu plan no incluye este detalle.');

        abort_unless($access->canDownload($type), 403, match ($type) {
            'skp' => 'Los archivos SKP son exclusivos del plan Pro.',
            default => 'Tu plan no incluye la descarga de archivos DWG. Pasate a Estudiante o Pro.',
        });

        $file = $detail->files()->where('type', $type)->first();
        $disk = DetailFile::disk();
        abort_unless($file?->path && $disk->exists($file->path), 404, 'Este detalle no tiene archivo ' . strtoupper($type) . '.');

        // Se entrega en streaming con el nombre original: la ruta real en el disco nunca sale del servidor
        return $disk->download($file->path, $file->original_name ?? "detalle-{$detail->id}.{$type}");
    }
}
