<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Mi cuenta: datos del perfil, foto y portada, contraseña y sesiones abiertas. */
class AccountController extends Controller
{
    private const IMAGE_COLUMNS = ['avatar' => 'avatar_url', 'cover' => 'cover_url'];

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'profession' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'portfolio_url' => ['nullable', 'url:http,https', 'max:2048'],
        ], [
            'name.required' => 'Ingresá tu nombre.',
            'bio.max' => 'La biografía puede tener hasta 1000 caracteres.',
            'birth_date.date' => 'La fecha de nacimiento no es válida.',
            'birth_date.before' => 'La fecha de nacimiento no es válida.',
            'portfolio_url.url' => 'El link del portfolio no es válido (tiene que empezar con https://).',
        ]);

        $user = $request->user();
        $user->fill($data)->save();

        return response()->json(UserResource::make($user));
    }

    /** Sube la foto de perfil o la portada ($kind: avatar | cover). */
    public function uploadImage(Request $request, string $kind): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'image.required' => 'Elegí una imagen.',
            'image.uploaded' => 'No se pudo subir la imagen (máximo 2 MB).',
            'image.image' => 'El archivo tiene que ser una imagen.',
            'image.mimes' => 'La imagen tiene que ser JPG, PNG o WebP.',
            'image.max' => 'La imagen puede pesar hasta 2 MB.',
        ]);

        $user = $request->user();
        $column = self::IMAGE_COLUMNS[$kind];
        $path = $request->file('image')->store("{$kind}s", 'public');

        $this->deleteStoredImage($user->{$column});
        $user->forceFill([$column => Storage::disk('public')->url($path)])->save();

        return response()->json(UserResource::make($user));
    }

    public function deleteImage(Request $request, string $kind): JsonResponse
    {
        $user = $request->user();
        $column = self::IMAGE_COLUMNS[$kind];

        $this->deleteStoredImage($user->{$column});
        $user->forceFill([$column => null])->save();

        return response()->json(UserResource::make($user));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed', 'different:current_password'],
        ], [
            'current_password.required' => 'Ingresá tu contraseña actual.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'password.required' => 'Ingresá la contraseña nueva.',
            'password.min' => 'La contraseña nueva debe tener al menos 8 caracteres.',
            'password.max' => 'La contraseña nueva puede tener hasta 72 caracteres.',
            'password.confirmed' => 'Las contraseñas nuevas no coinciden.',
            'password.different' => 'La contraseña nueva tiene que ser distinta de la actual.',
        ]);

        $user = $request->user();
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $closed = $this->closeOtherSessions($request);

        return response()->json(['message' => 'Contraseña actualizada.', 'closed_sessions' => $closed]);
    }

    /** Sesiones abiertas del usuario (el driver de sesión es "database"). */
    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->session()->getId();

        $sessions = DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($s) => [
                'device' => $this->describeDevice((string) $s->user_agent),
                'ip_address' => $s->ip_address,
                'last_activity' => date(DATE_ATOM, $s->last_activity),
                'current' => $s->id === $currentId,
            ]);

        return response()->json($sessions);
    }

    public function logoutOtherSessions(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'current_password:web'],
        ], [
            'password.required' => 'Ingresá tu contraseña para confirmar.',
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        return response()->json(['closed_sessions' => $this->closeOtherSessions($request)]);
    }

    private function closeOtherSessions(Request $request): int
    {
        return DB::table('sessions')
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();
    }

    // Solo se borran archivos subidos acá (no links externos como los de los datos demo)
    private function deleteStoredImage(?string $url): void
    {
        $base = Storage::disk('public')->url('');
        if ($url && Str::startsWith($url, $base)) {
            Storage::disk('public')->delete(Str::after($url, $base));
        }
    }

    // "Chrome en Windows", "Safari en iPhone", ...
    private function describeDevice(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Navegador',
        };

        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$browser} en {$os}" : $browser;
    }
}
