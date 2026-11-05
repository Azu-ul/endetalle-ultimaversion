<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--manual : Marca el backup como manual (por defecto es automático)}';

    protected $description = 'Crea un backup completo (base de datos + archivos del sitio) en storage/app/backups';

    public function handle(BackupService $backups): int
    {
        try {
            $b = $backups->create($this->option('manual') ? 'manual' : 'auto');
        } catch (\Throwable $e) {
            report($e);
            $this->error('No se pudo crear el backup: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup creado: {$b['name']} (" . round($b['size'] / 1048576, 2) . ' MB)');

        return self::SUCCESS;
    }
}
