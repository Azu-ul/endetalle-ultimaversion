<?php

namespace App\Http\Controllers;

use App\Models\ErrorReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErrorReportController extends Controller
{
    public function store(Request $request, int $id): JsonResponse
    {
        $detail = $this->openableDetail($request, $id);

        $data = $request->validate([
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'description.required' => 'Contanos cuál es el error.',
            'description.min' => 'Contanos un poco más (al menos 10 caracteres).',
            'description.max' => 'El reporte puede tener hasta 2000 caracteres.',
        ]);

        ErrorReport::create([
            'detail_id' => $detail->id,
            'user_id' => $request->user()->id,
            'description' => $data['description'],
        ]);

        return response()->json(['ok' => true], 201);
    }
}
