<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SiteController;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Alta de categorías y subcategorías desde el panel admin (dos niveles: categoría > subcategoría). */
class CategoryController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // Sin parent_id es una categoría; con parent_id es una subcategoría de esa categoría
            'parent_id' => ['nullable', 'integer', 'exists:categories,id,parent_id,NULL'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
        ], [
            'name.required' => 'Escribí el nombre.',
            'name.max' => 'El nombre puede tener hasta 100 caracteres.',
            'parent_id.exists' => 'La categoría elegida no existe (las subcategorías no pueden tener subcategorías).',
            'image_url.url' => 'La imagen tiene que ser un link (https://...).',
        ]);

        $name = trim($data['name']);
        $parentId = $data['parent_id'] ?? null;
        $slug = Str::slug($name);
        if ($slug === '') {
            return response()->json(['message' => 'El nombre tiene que tener letras o números.', 'errors' => ['name' => ['El nombre tiene que tener letras o números.']]], 422);
        }

        // Ni el nombre ni el slug se pueden repetir dentro del mismo nivel
        $siblings = Category::where('parent_id', $parentId);
        if ((clone $siblings)->where('slug', $slug)->exists() || (clone $siblings)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return response()->json([
                'message' => $parentId ? 'Esa categoría ya tiene una subcategoría con ese nombre.' : 'Ya existe una categoría con ese nombre.',
                'errors' => ['name' => [$parentId ? 'Esa categoría ya tiene una subcategoría con ese nombre.' : 'Ya existe una categoría con ese nombre.']],
            ], 422);
        }

        Category::create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'image_url' => $parentId ? null : ($data['image_url'] ?? null),
            'sort_order' => ((int) (clone $siblings)->max('sort_order')) + 1,
        ]);

        // Mismo formato que la API pública (categorías con sus subcategorías)
        return (new SiteController())->categories()->setStatusCode(201);
    }
}
