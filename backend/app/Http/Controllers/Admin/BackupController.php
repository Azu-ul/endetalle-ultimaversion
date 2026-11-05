<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Backups del sitio y la base de datos (solo superadmin: ver rutas). */
class BackupController extends Controller
{
    public function __construct(private BackupService $backups)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'backups' => $this->backups->list(),
            'auto_time' => config('backup.auto_time'),
            'keep_auto' => (int) config('backup.keep_auto'),
            'keep_manual' => (int) config('backup.keep_manual'),
        ]);
    }

    /** Backup manual. Puede tardar un rato si hay muchos archivos subidos. */
    public function store(): JsonResponse
    {
        try {
            $backup = $this->backups->create('manual');
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo crear el backup: ' . $e->getMessage()], 500);
        }

        return response()->json($backup, 201);
    }

    public function download(string $name): BinaryFileResponse
    {
        $path = $this->backups->path($name);
        abort_unless($path, 404, 'Ese backup no existe.');

        return response()->download($path, $name);
    }

    public function destroy(string $name): JsonResponse
    {
        abort_unless($this->backups->delete($name), 404, 'Ese backup no existe.');

        return response()->json(['deleted' => true]);
    }
}
