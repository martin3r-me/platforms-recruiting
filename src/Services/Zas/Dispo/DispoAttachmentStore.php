<?php

namespace Platform\Recruiting\Services\Zas\Dispo;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Platform\Recruiting\Models\RecDispoAttachment;
use Symfony\Component\Uid\UuidV7;

/**
 * Datei-Lifecycle der Anhaenge: seit Crew-Info (03.09.) MEHRERE Dateien pro
 * MA und VA (Einteilung, Briefing, Zugangscode, ...) — Hochladen fuegt hinzu,
 * geloescht wird gezielt je Datei (Zeile zuerst, Datei danach). Filesystem
 * wird injiziert, damit Integration-Tests ohne Laravel-Container laufen
 * (Flysystem-Local auf Temp-Verzeichnis); Produktion nutzt ::default().
 */
class DispoAttachmentStore
{
    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(private Filesystem $files, private string $diskName)
    {
    }

    public static function default(): self
    {
        $disk = (string) config('recruiting.zas.inbound_disk', 'local');

        return new self(\Illuminate\Support\Facades\Storage::disk($disk), $disk);
    }

    public function putUpload(int $eventId, int $employeeId, UploadedFile $file, ?int $userId = null): RecDispoAttachment
    {
        return $this->putContents(
            $eventId,
            $employeeId,
            (string) file_get_contents($file->getRealPath()),
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            $userId
        );
    }

    /** Legt IMMER neu an (seit Crew-Info 03.09. sind mehrere Dateien je MA+VA erlaubt). */
    public function putContents(int $eventId, int $employeeId, string $contents, string $originalFilename, ?string $mime, ?int $userId = null): RecDispoAttachment
    {
        $uuid = (string) UuidV7::generate();
        $ext = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
        $path = "zas-dispo-attachments/{$eventId}/{$uuid}.{$ext}";

        $this->files->put($path, $contents);

        return RecDispoAttachment::create([
            'uuid'                => $uuid,
            'rec_dispo_event_id'  => $eventId,
            'rec_employee_id'     => $employeeId,
            'disk'                => $this->diskName,
            'stored_path'         => $path,
            'original_filename'   => mb_substr($originalFilename, 0, 255),
            'mime_type'           => $mime,
            'size_bytes'          => strlen($contents),
            'uploaded_by_user_id' => $userId,
        ]);
    }

    public function remove(RecDispoAttachment $attachment): void
    {
        $this->deleteFile($attachment->stored_path);
        $attachment->delete();
    }

    /** Alle Anhaenge (fuer dispo-reset). @return int geloeschte Zeilen */
    public function removeAll(): int
    {
        $count = 0;
        RecDispoAttachment::query()->orderBy('id')->chunkById(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $this->remove($row);
                $count++;
            }
        });

        return $count;
    }

    private function deleteFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }
        try {
            $this->files->delete($path);
        } catch (\Throwable) {
            // Datei-Loeschung darf den DB-Vorgang nie kippen (verwaiste Datei < verwaiste Zeile).
        }
    }
}
