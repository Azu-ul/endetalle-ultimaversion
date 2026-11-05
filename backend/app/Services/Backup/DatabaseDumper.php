<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vuelca la base de datos a un .sql que se importa tal cual desde phpMyAdmin.
 * Hecho en PHP puro: en el hosting compartido no hay mysqldump garantizado ni terminal.
 */
class DatabaseDumper
{
    private const BATCH = 200;

    // Se guarda la estructura pero no los datos: sesiones y caché no sirven en una restauración (y las sesiones son sensibles)
    private const SKIP_DATA = ['cache', 'cache_locks', 'sessions'];

    /** @return int cantidad de tablas volcadas */
    public function dump(string $path): int
    {
        $out = fopen($path, 'wb');
        if (! $out) {
            throw new \RuntimeException('No se pudo escribir el archivo de la base de datos.');
        }

        $pdo = DB::connection()->getPdo();
        $driver = DB::connection()->getDriverName();
        $tables = $this->tables($driver);

        fwrite($out, "-- Backup de la base de datos · " . now()->toDateTimeString() . "\n");
        fwrite($out, "-- Se importa desde phpMyAdmin (pestaña Importar) sobre una base vacía.\n\n");
        fwrite($out, $driver === 'mysql' ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" : "PRAGMA foreign_keys=OFF;\n\n");

        foreach ($tables as $table) {
            fwrite($out, "-- Tabla {$table}\nDROP TABLE IF EXISTS " . $this->q($table, $driver) . ";\n");
            fwrite($out, $this->createStatement($table, $driver) . ";\n\n");
            if (! in_array($table, self::SKIP_DATA, true)) {
                $this->rows($out, $pdo, $table, $driver);
            }
            fwrite($out, "\n");
        }

        fwrite($out, $driver === 'mysql' ? "SET FOREIGN_KEY_CHECKS=1;\n" : "PRAGMA foreign_keys=ON;\n");
        fclose($out);

        return count($tables);
    }

    /** @return string[] */
    private function tables(string $driver): array
    {
        if ($driver === 'sqlite') {
            return array_column(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"), 'name');
        }

        // Solo tablas reales (no vistas)
        return array_map(fn ($r) => array_values((array) $r)[0], DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
    }

    private function createStatement(string $table, string $driver): string
    {
        if ($driver === 'sqlite') {
            return rtrim((string) DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table])->sql, ';');
        }

        return rtrim(array_values((array) DB::selectOne('SHOW CREATE TABLE ' . $this->q($table, $driver)))[1], ';');
    }

    /** @param resource $out */
    private function rows($out, \PDO $pdo, string $table, string $driver): void
    {
        $columns = Schema::getColumnListing($table);
        if (! $columns) {
            return;
        }
        $head = 'INSERT INTO ' . $this->q($table, $driver) . ' (' . implode(', ', array_map(fn ($c) => $this->q($c, $driver), $columns)) . ') VALUES';

        $write = function ($rows) use ($out, $pdo, $head, $columns) {
            if ($rows->isEmpty()) {
                return;
            }
            $values = $rows->map(function ($row) use ($pdo, $columns) {
                $row = (array) $row;

                return '(' . implode(', ', array_map(function ($c) use ($pdo, $row) {
                    $v = $row[$c] ?? null;

                    return $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v));
                }, $columns)) . ')';
            })->implode(",\n");
            fwrite($out, $head . "\n" . $values . ";\n");
        };

        if (in_array('id', $columns, true)) {
            DB::table($table)->orderBy('id')->chunkById(self::BATCH, $write);
        } else {
            DB::table($table)->get()->chunk(self::BATCH)->each($write);
        }
    }

    private function q(string $name, string $driver): string
    {
        return $driver === 'mysql' ? '`' . str_replace('`', '``', $name) . '`' : '"' . str_replace('"', '""', $name) . '"';
    }
}
