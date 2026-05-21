<?php

namespace Platform\Recruiting\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Storage;
use Platform\Core\Models\ContextFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generischer Stream einer ContextFile fuer ZAS-Endpunkte.
 *
 * Logik:
 *  - Variants bevorzugt (medium_* fuer Bilder, ~30-160 KB statt 1-3 MB)
 *  - WebP → JPG-Konvertierung on-the-fly (MS Access kann kein WebP)
 *  - Cache-Control: no-store (Inhalt kann sich aendern)
 *
 * Geteilte Implementierung zwischen ZasFileController (Bewerber) und
 * ZasEmployeeFileController (Mitarbeiter). Beide haben dieselbe Datei-
 * Stream-Anforderung, nur die Slot-Resolution unterscheidet sich.
 */
trait StreamsZasContextFile
{
    /**
     * Streamt eine ContextFile von ihrem Storage-Disk. Bevorzugt medium-
     * Variant, faellt sonst aufs Original zurueck. WebP-Bilder werden
     * on-the-fly zu JPG konvertiert.
     */
    protected function streamContextFile(ContextFile $file): Response
    {
        $variant = $file->variants()
            ->where('variant_type', 'like', 'medium_%')
            ->first();

        if ($variant) {
            $disk = Storage::disk($variant->disk ?? 'local');
            $path = $variant->path;
            $sourceMime = 'image/webp';
            $servedAs = $variant->variant_type;
        } else {
            $disk = Storage::disk($file->disk ?? 'local');
            $path = $file->path;
            $sourceMime = $file->mime_type ?: 'application/octet-stream';
            $servedAs = 'original';
        }

        if (!$disk->exists($path)) {
            return response('File missing on disk', 404)->header('Cache-Control', 'no-store');
        }

        $needsJpegConversion = str_starts_with($sourceMime, 'image/')
            && !in_array($sourceMime, ['image/jpeg', 'image/jpg'], true);

        if ($needsJpegConversion) {
            return $this->streamAsJpeg($disk, $path, $file, $servedAs, $sourceMime);
        }

        $filename = $file->original_name ?: $file->file_name;
        $size = $variant?->file_size ?: ($file->file_size ?: '');

        return new StreamedResponse(
            function () use ($disk, $path) {
                $stream = $disk->readStream($path);
                if ($stream === null) {
                    return;
                }
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type'        => $sourceMime,
                'Content-Length'      => (string) $size,
                'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
                'Cache-Control'       => 'no-store',
                'X-Variant-Served'    => $servedAs,
            ]
        );
    }

    /**
     * Liest Bild-Bytes, konvertiert zu JPG (Intervention Image / GD-Driver),
     * gibt als image/jpeg-Response zurueck.
     */
    protected function streamAsJpeg(
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
        string $path,
        ContextFile $file,
        string $servedAs,
        string $sourceMime,
    ): Response {
        $sourceBytes = $disk->get($path);
        if ($sourceBytes === null) {
            return response('File missing on disk', 404)->header('Cache-Control', 'no-store');
        }

        try {
            $manager = new \Intervention\Image\ImageManager(
                new \Intervention\Image\Drivers\Gd\Driver()
            );
            $image = $manager->read($sourceBytes);
            $jpegBytes = (string) $image->toJpeg(90);
        } catch (\Throwable $e) {
            \Log::warning('[ZAS-StreamsContextFile] WebP-JPG-Konvertierung fehlgeschlagen', [
                'context_file_id' => $file->id,
                'error' => $e->getMessage(),
            ]);
            $jpegBytes = $sourceBytes;
        }

        $filename = $this->zasVariantFilename($file, 'medium');

        return response($jpegBytes, 200, [
            'Content-Type'        => 'image/jpeg',
            'Content-Length'      => (string) strlen($jpegBytes),
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'Cache-Control'       => 'no-store',
            'X-Variant-Served'    => $servedAs,
            'X-Format-Converted'  => $sourceMime . '->image/jpeg',
        ]);
    }

    /**
     * Sprechender JPG-Filename fuer die ZAS-Auslieferung.
     */
    protected function zasVariantFilename(ContextFile $file, string $sizeName): string
    {
        $base = $file->original_name ?: $file->file_name;
        if ($base === '') {
            return $sizeName . '.jpg';
        }
        $dot = strrpos($base, '.');
        if ($dot === false) {
            return $base . '-' . $sizeName . '.jpg';
        }
        $name = substr($base, 0, $dot);
        return $name . '-' . $sizeName . '.jpg';
    }
}
