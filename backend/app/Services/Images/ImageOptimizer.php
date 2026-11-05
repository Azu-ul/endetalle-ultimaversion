<?php

namespace App\Services\Images;

use Illuminate\Validation\ValidationException;

/**
 * Reduce imágenes con GD: lado máximo y peso máximo. Lo que sale nunca pasa del límite de bytes
 * (baja la calidad primero y, si hace falta, el tamaño).
 */
class ImageOptimizer
{
    public static function supportsWebp(): bool
    {
        return function_exists('imagewebp');
    }

    /**
     * @return array{data: string, ext: string, mime: string, width: int, height: int}
     */
    public static function encode(string $binary, int $maxEdge, int $maxBytes, string $format = 'webp'): array
    {
        $format = ($format === 'webp' && self::supportsWebp()) ? 'webp' : ($format === 'png' ? 'png' : 'jpg');
        $image = self::decode($binary);

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxEdge / max($width, $height));
        $tries = 0;

        do {
            $w = max(1, (int) round($width * $scale));
            $h = max(1, (int) round($height * $scale));
            $canvas = self::resample($image, $w, $h, $format !== 'jpg');

            $data = null;
            foreach (self::qualities($format) as $quality) {
                $data = self::render($canvas, $format, $quality);
                if (strlen($data) <= $maxBytes) {
                    break 2;
                }
            }

            $scale *= 0.85; // ni con la calidad mínima alcanzó: se achica
        } while (++$tries < 8);

        return [
            'data' => $data,
            'ext' => $format,
            'mime' => $format === 'jpg' ? 'image/jpeg' : "image/{$format}",
            'width' => $w,
            'height' => $h,
        ];
    }

    private static function decode(string $binary): \GdImage
    {
        $info = @getimagesizefromstring($binary);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['image' => 'La imagen tiene que ser JPG, PNG o WebP.']);
        }

        $maxPixels = (int) config('cms.images.max_pixels');
        if ($info[0] * $info[1] > $maxPixels) {
            throw ValidationException::withMessages(['image' => 'La imagen es demasiado grande (más de 30 megapíxeles). Achicala antes de subirla.']);
        }

        $image = @imagecreatefromstring($binary);
        if (! $image) {
            throw ValidationException::withMessages(['image' => 'No se pudo leer la imagen. Probá con otro archivo.']);
        }

        // Las fotos del celular vienen giradas con la orientación en los metadatos EXIF
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($binary));
            $rotate = match ($exif['Orientation'] ?? 1) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($rotate !== 0) {
                $rotated = imagerotate($image, $rotate, 0);
                if ($rotated) {
                    $image = $rotated; // GD libera la imagen anterior sola (PHP 8)
                }
            }
        }

        return $image;
    }

    private static function resample(\GdImage $image, int $w, int $h, bool $keepAlpha): \GdImage
    {
        $canvas = imagecreatetruecolor($w, $h);
        if ($keepAlpha) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        } else {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // JPG no tiene transparencia: fondo blanco
        }
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, imagesx($image), imagesy($image));

        return $canvas;
    }

    /** @return int[] */
    private static function qualities(string $format): array
    {
        return match ($format) {
            'png' => [6],
            'webp' => [86, 78, 70, 62, 54, 46],
            default => [90, 84, 78, 72, 66, 58],
        };
    }

    private static function render(\GdImage $canvas, string $format, int $quality): string
    {
        ob_start();
        match ($format) {
            'webp' => imagewebp($canvas, null, $quality),
            'png' => imagepng($canvas, null, $quality),
            default => (function () use ($canvas, $quality) {
                imageinterlace($canvas, true);
                imagejpeg($canvas, null, $quality);
            })(),
        };

        return (string) ob_get_clean();
    }
}
