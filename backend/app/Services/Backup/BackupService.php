<?php

namespace App\Services\Backup;

use App\Services\Export\UsersExport;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Backup completo en un solo .zip: la base de datos (database.sql) + los archivos del sitio
 * (código, archivos subidos y comprobantes). No incluye .env (guarda contraseñas) ni vendor (se reinstala).
 */
class BackupService
{
    // El zip abre un descriptor por archivo hasta cerrarse: se cierra y reabre cada tanto
    private const FILES_PER_BATCH = 200;

    public function __construct(private DatabaseDumper $dumper)
    {
    }

    public function dir(): string
    {
        $dir = config('backup.path');
        if (! is_dir($dir)) {
            File::makeDirectory($dir, 0750, true);
            // Por si alguna vez la carpeta queda expuesta por el servidor web
            File::put($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }

        return $dir;
    }

    /** @return array{name: string, type: string, size: int, created_at: string} */
    public function create(string $type = 'manual'): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('El servidor no tiene habilitada la extensión ZIP de PHP.');
        }
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $dir = $this->dir();
        $base = 'backup-' . now()->format('Y-m-d_His') . '-' . $type;
        $zipPath = "{$dir}/{$base}.zip";
        $tmpPath = "{$zipPath}.part";
        $sql = "{$dir}/{$base}.sql.tmp";

        try {
            $this->dumper->dump($sql);

            $zip = new ZipArchive();
            if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear el archivo zip (¿falta espacio en el hosting?).');
            }
            $zip->addFile($sql, 'database.sql');
            $zip->addFromString('LEEME.txt', $this->readme());
            // Resguardo legible de usuarios y pagos: se abre directo en Excel
            try {
                $zip->addFromString('usuarios.csv', app(UsersExport::class)->usersCsv());
            } catch (\Throwable $e) {
                report($e); // el backup sigue sin este archivo
            }
            $zip->close();

            $this->addSiteFiles($tmpPath);

            if (! @rename($tmpPath, $zipPath)) {
                throw new \RuntimeException('No se pudo terminar el archivo de backup.');
            }
        } finally {
            @unlink($sql);
            @unlink($tmpPath);
        }

        $this->prune();

        return $this->describe($zipPath);
    }

    /** @return array<int, array{name: string, type: string, size: int, created_at: string}> más nuevos primero */
    public function list(): array
    {
        $files = glob($this->dir() . '/backup-*.zip') ?: [];
        $items = array_map(fn ($f) => $this->describe($f), $files);
        usort($items, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $items;
    }

    /** Ruta de un backup por nombre (solo nombres que genera este servicio: nada de rutas). */
    public function path(string $name): ?string
    {
        if (! preg_match('/^backup-\d{4}-\d{2}-\d{2}_\d{6}-(manual|auto)\.zip$/', $name)) {
            return null;
        }
        $path = $this->dir() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);

        return $path !== null && @unlink($path);
    }

    private function addSiteFiles(string $zipPath): void
    {
        $root = base_path();
        $exclude = array_map(fn ($p) => rtrim($p, '/'), config('backup.exclude'));
        $files = [];

        foreach (config('backup.include_files') as $rel) {
            if (is_file("{$root}/{$rel}")) {
                $files[$rel] = "{$root}/{$rel}";
            }
        }
        foreach (config('backup.include') as $rel) {
            $abs = "{$root}/{$rel}";
            if (is_file($abs)) {
                $files[$rel] = $abs;
            } elseif (is_dir($abs)) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                );
                foreach ($it as $file) {
                    if (! $file->isFile() || $file->isLink()) {
                        continue;
                    }
                    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    foreach ($exclude as $ex) {
                        if ($path === $ex || str_starts_with($path, $ex . '/')) {
                            continue 2;
                        }
                    }
                    $files[$path] = $file->getPathname();
                }
            }
        }

        foreach (array_chunk($files, self::FILES_PER_BATCH, true) as $batch) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('No se pudo reabrir el zip del backup.');
            }
            foreach ($batch as $rel => $abs) {
                $zip->addFile($abs, 'sitio/' . $rel);
            }
            $zip->close();
        }
    }

    /** Deja los N más nuevos de cada tipo. */
    private function prune(): void
    {
        foreach (['auto' => (int) config('backup.keep_auto'), 'manual' => (int) config('backup.keep_manual')] as $type => $keep) {
            $ofType = array_values(array_filter($this->list(), fn ($b) => $b['type'] === $type));
            foreach (array_slice($ofType, max(1, $keep)) as $old) {
                $this->delete($old['name']);
            }
        }
    }

    private function describe(string $path): array
    {
        $name = basename($path);
        preg_match('/^backup-(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})-(manual|auto)\.zip$/', $name, $m);

        return [
            'name' => $name,
            'type' => $m[5] ?? 'manual',
            'size' => (int) filesize($path),
            'created_at' => isset($m[1]) ? \Illuminate\Support\Carbon::parse("{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}")->toIso8601String() : date('c', filemtime($path)),
        ];
    }

    private function readme(): string
    {
        return "BACKUP DE ENDETALLE\n"
            . "Creado: " . now()->toDateTimeString() . "\n\n"
            . "database.sql  -> la base de datos completa. Para restaurarla: phpMyAdmin > tu base > Importar > elegir este archivo.\n"
            . "sitio/        -> código, archivos subidos y comprobantes. Para restaurar, subí esa carpeta a /home/<usuario>/endetalle-app.\n\n"
            . "No incluye .env (contiene contraseñas y claves) ni la carpeta vendor (se reinstala con el zip de la aplicación).\n"
            . "Guardá una copia del .env por separado.\n";
    }
}
