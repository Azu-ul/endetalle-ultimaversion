<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // No existe una pantalla de "login" del lado del servidor (el sitio es una SPA): sin sesión, la API responde 401
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->api(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Sin sesión, la API responde 401 en JSON (antes, abrir un link de descarga sin sesión daba un error 500)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });

        // Un 404 común (ruta o registro que no existe) no cuenta detalles internos. Los avisos pensados para el
        // usuario ("Este detalle no tiene archivo SKP") siguen saliendo como están.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $generic = $e->getPrevious() instanceof ModelNotFoundException || str_starts_with($e->getMessage(), 'The route ');
            if ($generic && ($request->is('api/*') || $request->expectsJson())) {
                return response()->json(['message' => 'No encontrado.'], 404);
            }
        });
    })->create();
