<?php

namespace App\Http\Controllers;

use App\Models\Detail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Me gusta" / "No me gusta" de un detalle: una sola reacción por usuario (se puede cambiar o quitar).
 * Los contadores los ve cualquiera (ver DetailController::card); reaccionar pide cuenta con el email verificado.
 * El autor no reacciona a lo suyo.
 */
class ReactionController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reaction' => ['required', 'in:like,dislike']], [
            'reaction.required' => 'Elegí "me gusta" o "no me gusta".',
            'reaction.in' => 'La reacción no es válida.',
        ]);

        $detail = $this->openableDetail($request, $id);
        $userId = (int) $request->user()->id;
        abort_if((int) $detail->author_id === $userId, 422, 'No podés reaccionar a tu propio detalle.');

        DB::transaction(function () use ($detail, $userId, $data) {
            $new = $data['reaction'] === 'like' ? 1 : -1;
            $this->apply($detail->id, $userId, $new);
        });

        return $this->state($detail->id, $userId);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $detail = Detail::published()->findOrFail($id);
        $userId = (int) $request->user()->id;

        DB::transaction(fn () => $this->apply($detail->id, $userId, null));

        return $this->state($detail->id, $userId);
    }

    /**
     * Guarda la reacción de la persona (1, -1 o null para quitarla) y ajusta los contadores por la diferencia.
     * Se suma y se resta sobre el valor que ya tiene el detalle (en vez de recontar), así no se pierden los
     * "me gusta" que el detalle traía de antes.
     */
    private function apply(int $detailId, int $userId, ?int $new): void
    {
        $old = DB::table('detail_reactions')->where('user_id', $userId)->where('detail_id', $detailId)->value('value');
        $old = $old === null ? null : (int) $old;
        if ($old === $new) {
            return;
        }

        if ($new === null) {
            DB::table('detail_reactions')->where('user_id', $userId)->where('detail_id', $detailId)->delete();
        } else {
            DB::table('detail_reactions')->updateOrInsert(
                ['user_id' => $userId, 'detail_id' => $detailId],
                ['value' => $new, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $likes = (($new === 1) ? 1 : 0) - (($old === 1) ? 1 : 0);
        $dislikes = (($new === -1) ? 1 : 0) - (($old === -1) ? 1 : 0);
        DB::table('details')->where('id', $detailId)->update([
            'votes_count' => DB::raw('CASE WHEN votes_count + (' . $likes . ') < 0 THEN 0 ELSE votes_count + (' . $likes . ') END'),
            'dislikes_count' => DB::raw('CASE WHEN dislikes_count + (' . $dislikes . ') < 0 THEN 0 ELSE dislikes_count + (' . $dislikes . ') END'),
        ]);
    }

    private function state(int $detailId, int $userId): JsonResponse
    {
        $detail = DB::table('details')->where('id', $detailId)->first(['votes_count', 'dislikes_count']);
        $value = DB::table('detail_reactions')->where('user_id', $userId)->where('detail_id', $detailId)->value('value');

        return response()->json([
            'reaction' => $value === null ? null : ((int) $value === 1 ? 'like' : 'dislike'),
            'likes_count' => (int) $detail->votes_count,
            'dislikes_count' => (int) $detail->dislikes_count,
        ]);
    }
}
