<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Revisa que el archivo subido sea de verdad lo que dice su extensión, mirando los primeros bytes
 * (no alcanza con el nombre: alguien puede renombrar un .exe a .dwg).
 *
 * - DWG: todas las versiones de AutoCAD empiezan con el texto "AC" (por ejemplo AC1032) o "MC".
 * - SKP: se rechazan los formatos que no pueden ser un modelo de SketchUp (ejecutables, zip, PDF, scripts, HTML...).
 */
class DetailFileSignature implements ValidationRule
{
    /** Cabeceras que nunca son un SKP. */
    private const NOT_SKETCHUP = ['MZ', 'PK', '%PDF', "\x7fELF", '#!', '<?', '<!', '<html', '<script', 'GIF8', "\x89PNG", "\xFF\xD8\xFF"];

    public function __construct(private string $type)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return; // las demás reglas se ocupan
        }

        $head = (string) file_get_contents($value->getRealPath(), false, null, 0, 16);
        $label = strtoupper($this->type);

        if ($head === '') {
            $fail("El archivo {$label} está vacío.");

            return;
        }

        if ($this->type === 'dwg' && ! str_starts_with($head, 'AC') && ! str_starts_with($head, 'MC')) {
            $fail('El archivo no parece un DWG válido: guardalo de nuevo desde AutoCAD.');
        }

        if ($this->type === 'skp') {
            foreach (self::NOT_SKETCHUP as $signature) {
                if (str_starts_with(strtolower($head), strtolower($signature))) {
                    $fail('El archivo no parece un modelo de SketchUp válido.');

                    return;
                }
            }
        }
    }
}
