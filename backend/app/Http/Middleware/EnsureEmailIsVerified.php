<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Con el email sin verificar no se interactúa (favoritos, votos, comentarios, "me gusta"). El staff queda exento. */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasVerifiedEmail() && ! $user->hasAnyRole(['superadmin', 'colaborador'])) {
            return response()->json([
                'message' => 'Verificá tu email para hacer esto. Te mandamos un enlace al registrarte.',
                'code' => 'email_unverified',
            ], 403);
        }

        return $next($request);
    }
}
