<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /** Responde siempre lo mismo: no revela si el email tiene cuenta. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']], [
            'email.required' => 'Ingresá tu email.',
            'email.email' => 'El email no es válido.',
        ]);

        try {
            Password::sendResetLink($data);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Si el email tiene una cuenta, te enviamos un enlace para restablecer la contraseña.']);
    }

    /** Para avisar apenas se abre el enlace si ya se usó o venció. */
    public function check(Request $request): JsonResponse
    {
        $token = (string) $request->query('token', '');
        $user = Password::getUser(['email' => (string) $request->query('email', '')]);

        return response()->json([
            'valid' => $token !== '' && $user !== null && Password::tokenExists($user, $token),
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:72'],
        ], [
            'password.required' => 'Ingresá una contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $status = Password::reset($data, function ($user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ]);
            // Si pudo abrir el email, también queda verificado
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
            $user->save();

            // Se cierran las sesiones que hubiera abiertas
            DB::table('sessions')->where('user_id', $user->id)->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['El enlace venció o no es válido. Pedí uno nuevo.'],
            ]);
        }

        return response()->json(['message' => 'Listo, ya podés ingresar con tu contraseña nueva.']);
    }
}
