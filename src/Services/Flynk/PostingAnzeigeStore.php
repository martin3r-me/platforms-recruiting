<?php

namespace Platform\Recruiting\Services\Flynk;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Platform\Recruiting\Models\RecPosting;
use Symfony\Component\Uid\UuidV7;

/**
 * Die Stellenanzeige als Datei an der Ausschreibung — genau EINE je
 * Ausschreibung. Hochladen ersetzt, Entfernen leert die Spalten.
 *
 * Dateitypen: die von FLYNK genannten (Mail 11/2026: PDF und DOCX).
 *
 * Groesse: FLYNK nimmt bis 50 MB, WIR nehmen bis 12 MB — das ist die Grenze des
 * Livewire-Temp-Uploads der Host-App (config/livewire.php, Standard 12288 KB).
 * Eine groessere Zahl hier waere eine Zusage, die der Upload nie einloest: die
 * Datei scheitert dann schon vor dieser Pruefung mit einer nichtssagenden
 * Meldung. Wer die Grenze heben will, hebt zuerst die der Host-App (Livewire,
 * PHP upload_max_filesize/post_max_size, Webserver) und dann diese.
 *
 * Die Grenzen stehen HIER und nicht nur in der Formular-Regel, damit Formular
 * und Ablage nie auseinanderlaufen.
 *
 * Filesystem wird injiziert (Tests: Flysystem-Local im Temp-Verzeichnis);
 * Produktion nutzt ::default().
 */
class PostingAnzeigeStore
{
    public const ALLOWED_EXTENSIONS = ['pdf', 'docx'];
    public const MAX_BYTES = 12 * 1024 * 1024;
    public const MAX_LABEL = '12 MB';

    public function __construct(private Filesystem $files, private string $diskName)
    {
    }

    public static function default(): self
    {
        $disk = (string) (config('recruiting.flynk.disk') ?: config('recruiting.zas.inbound_disk', 'local'));

        return new self(Storage::disk($disk), $disk);
    }

    /** null = erlaubt, sonst der Grund in Klartext (fuer die Formular-Meldung). */
    public static function rejectionReason(string $originalFilename, int $sizeBytes): ?string
    {
        $ext = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return 'Nur PDF- oder DOCX-Dateien sind möglich.';
        }
        if ($sizeBytes <= 0) {
            return 'Die Datei ist leer.';
        }
        if ($sizeBytes > self::MAX_BYTES) {
            return 'Die Datei ist größer als ' . self::MAX_LABEL . '.';
        }

        return null;
    }

    /**
     * Legt die neue Datei ab, schreibt die Spalten und raeumt die alte Datei
     * DANACH weg (neue zuerst: schlaegt das Schreiben fehl, bleibt die alte).
     */
    public function put(RecPosting $posting, string $contents, string $originalFilename, ?string $mime): void
    {
        $reason = self::rejectionReason($originalFilename, strlen($contents));
        if ($reason !== null) {
            throw new \InvalidArgumentException($reason);
        }

        $ext = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $path = "posting-anzeigen/{$posting->id}/" . UuidV7::generate() . ".{$ext}";
        $this->files->put($path, $contents);

        $oldPath = $posting->anzeige_path;
        $oldDisk = $posting->anzeige_disk;

        $posting->forceFill([
            'anzeige_disk' => $this->diskName,
            'anzeige_path' => $path,
            'anzeige_filename' => mb_substr(basename($originalFilename), 0, 255),
            'anzeige_mime' => $mime !== null ? mb_substr($mime, 0, 120) : null,
            'anzeige_size' => strlen($contents),
            'anzeige_hash' => hash('sha256', $contents),
        ])->save();

        if ($oldPath && $oldPath !== $path) {
            $this->deleteFile($oldPath, $oldDisk);
        }
    }

    public function remove(RecPosting $posting): void
    {
        $oldPath = $posting->anzeige_path;
        $oldDisk = $posting->anzeige_disk;

        $posting->forceFill([
            'anzeige_disk' => null, 'anzeige_path' => null, 'anzeige_filename' => null,
            'anzeige_mime' => null, 'anzeige_size' => null, 'anzeige_hash' => null,
        ])->save();

        if ($oldPath) {
            $this->deleteFile($oldPath, $oldDisk);
        }
    }

    /** Inhalt der Datei oder null, wenn keine hinterlegt ist bzw. sie fehlt. */
    public function contents(RecPosting $posting): ?string
    {
        if (!$posting->anzeige_path) {
            return null;
        }
        try {
            $contents = $this->diskFor($posting->anzeige_disk)->get($posting->anzeige_path);
        } catch (\Throwable) {
            return null;
        }

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * Die Disk, auf der DIESE Datei liegt (Spalte anzeige_disk) — nicht blind
     * die heute konfigurierte. Wird die Konfiguration umgestellt, bleiben die
     * schon abgelegten Dateien lesbar.
     */
    private function diskFor(?string $disk): Filesystem
    {
        if ($disk === null || $disk === '' || $disk === $this->diskName) {
            return $this->files;
        }

        return Storage::disk($disk);
    }

    private function deleteFile(string $path, ?string $disk = null): void
    {
        try {
            $this->diskFor($disk)->delete($path);
        } catch (\Throwable) {
            // Datei-Loeschung darf den DB-Vorgang nie kippen.
        }
    }
}
