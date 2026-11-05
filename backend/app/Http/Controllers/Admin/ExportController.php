<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Export\UsersExport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Descarga de usuarios y clientes (Excel o CSV) para tener un resguardo propio. */
class ExportController extends Controller
{
    public function users(Request $request, UsersExport $export): Response
    {
        $format = $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['xlsx', 'csv'], true), 422);
        $name = 'usuarios-endetalle-' . now(config('cms.display_timezone'))->format('Y-m-d_H-i');

        if ($format === 'csv') {
            return response($export->usersCsv(), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
                'Cache-Control' => 'no-store',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'usr');
        $export->toXlsx($tmp);

        return response()->download($tmp, "{$name}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }
}
