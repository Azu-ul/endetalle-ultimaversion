<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Formulario de contacto: queda guardado y le llega por email al equipo. */
class ContactController extends Controller
{
    private const SUBJECTS = ['Consulta General', 'Soporte Técnico', 'Suscripciones', 'Prensa / Colaboraciones'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'in:' . implode(',', self::SUBJECTS)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [
            'name.required' => 'Ingresá tu nombre.',
            'email.required' => 'Ingresá tu email.',
            'email.email' => 'El email no es válido.',
            'subject.in' => 'Elegí un asunto.',
            'message.required' => 'Escribí tu mensaje.',
            'message.min' => 'El mensaje es muy corto.',
            'message.max' => 'El mensaje puede tener hasta 5000 caracteres.',
        ]);

        DB::table('contact_messages')->insert($data + [
            'user_id' => $request->user('sanctum')?->id,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Si el correo falla, el mensaje igual quedó guardado
        if ($to = config('mail.contact_to')) {
            try {
                Mail::raw(
                    "Nombre: {$data['name']}\nEmail: {$data['email']}\nAsunto: {$data['subject']}\n\n{$data['message']}",
                    fn ($m) => $m->to($to)->replyTo($data['email'], $data['name'])->subject("[Contacto] {$data['subject']}")
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => 'Mensaje enviado. Te respondemos a la brevedad.'], 201);
    }
}
