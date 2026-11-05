<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Etiquetas de los detalles (ayudan al SEO): el admin las crea, renombra y borra. */
class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tag::withCount('details')->orderBy('name');

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $query->where('name', 'like', $like);
        }

        return response()->json($query->limit(1000)->get()->map(fn (Tag $t) => $this->serialize($t)));
    }

    public function store(Request $request): JsonResponse
    {
        $name = $this->validName($request);
        $slug = Str::slug($name);
        abort_if(Tag::where('slug', $slug)->exists(), 422, 'Ya existe una etiqueta con ese nombre.');

        $tag = Tag::create(['name' => $name, 'slug' => $slug]);

        return response()->json($this->serialize($tag->loadCount('details')), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tag = Tag::findOrFail($id);
        $name = $this->validName($request);
        $slug = Str::slug($name);
        abort_if(Tag::where('slug', $slug)->where('id', '!=', $tag->id)->exists(), 422, 'Ya existe otra etiqueta con ese nombre.');

        $tag->update(['name' => $name, 'slug' => $slug]);

        return response()->json($this->serialize($tag->loadCount('details')));
    }

    /** Se quita de todos los detalles que la usaban (los detalles no se tocan). */
    public function destroy(int $id): JsonResponse
    {
        Tag::findOrFail($id)->delete();

        return response()->json(['deleted' => true]);
    }

    private function validName(Request $request): string
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:50']], [
            'name.required' => 'Escribí el nombre de la etiqueta.',
            'name.max' => 'La etiqueta puede tener hasta 50 caracteres.',
        ]);
        $name = trim(preg_replace('/\s+/', ' ', $data['name']));
        abort_if(Str::slug($name) === '', 422, 'La etiqueta tiene que tener letras o números.');

        return $name;
    }

    private function serialize(Tag $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'details_count' => (int) $t->details_count];
    }
}
