<?php

namespace App\Console\Commands;

use App\Services\Images\DetailImages;
use Illuminate\Console\Command;

class OptimizeImages extends Command
{
    protected $signature = 'images:optimize {--batch=10 : Imágenes por tanda}';

    protected $description = 'Optimiza (máx. 2 MB) y protege las imágenes de detalles cargadas antes de la v2';

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');
        $total = 0;
        $failed = 0;
        do {
            $r = DetailImages::migrateLegacy((int) $this->option('batch'));
            $total += $r['done'];
            $failed += $r['failed'];
            $this->line("Hechas: {$total}. Fallaron: {$failed}. Pendientes: {$r['pending']}");
        } while ($r['pending'] > 0 && ($r['done'] + $r['failed']) > 0);

        return self::SUCCESS;
    }
}
