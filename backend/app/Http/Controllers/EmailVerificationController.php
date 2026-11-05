<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /** Enlace firmado del email: no pide sesión (se puede abrir desde otro dispositivo). */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = $request->hasValidSignature() ? User::find($id) : null;
        $valid = $user && hash_equals(sha1($user->getEmailForVerification()), $hash);

        // 1 = verificada ahora, ya = el enlace se usó antes, 0 = enlace inválido o vencido
        $result = '0';
        if ($valid) {
            $result = $user->hasVerifiedEmail() ? 'ya' : '1';
            if ($result === '1') {
                $user->markEmailAsVerified();
            }
        }

        return redirect(rtrim(config('app.frontend_url'), '/') . '/?verificado=' . $result);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Tu email ya está verificado.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Te enviamos un email nuevo. Revisá también la carpeta de spam.']);
    }
}
