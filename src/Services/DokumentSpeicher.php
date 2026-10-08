<?php

namespace Platform\Recruiting\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Ablage der Dokument-PDFs (Spec §2.1, §3.1 Schritt 2). Eigener Speicher wie
 * die Dispo-Anhaenge (DispoAttachmentStore), KEIN ContextFile: die Auslieferung
 * an den Mitarbeiter braucht eine eigene Zugriffspruefung (DokumentZugriff),
 * die ContextFile nicht kennt. Die Pruefsumme entsteht aus den GESPEICHERTEN
 * Bytes, nicht aus dem Upload — so friert sie genau das ein, was der Mensch
 * spaeter vorgesetzt bekommt.
 */
class DokumentSpeicher
{
    public function __construct(private Filesystem $files, private string $diskName)
    {
    }

    public static function default(): self
    {
        $disk = (string) config('recruiting.zas.inbound_disk', 'local');

        return new self(Storage::disk($disk), $disk);
    }

    /** @return array{disk:string, stored_path:string, sha256:string, size:int} */
    public function ablegen(int $teamId, string $uuid, string $inhalt): array
    {
        $pfad = "recruiting/dokumente/{$teamId}/{$uuid}.pdf";
        $this->files->put($pfad, $inhalt);
        $gespeichert = (string) $this->files->get($pfad);

        return [
            'disk'        => $this->diskName,
            'stored_path' => $pfad,
            'sha256'      => hash('sha256', $gespeichert),
            'size'        => strlen($gespeichert),
        ];
    }

    public function inhalt(string $storedPath): ?string
    {
        if (!$this->files->exists($storedPath)) {
            return null;
        }

        return (string) $this->files->get($storedPath);
    }

    public function pruefsumme(string $storedPath): ?string
    {
        $inhalt = $this->inhalt($storedPath);

        return $inhalt === null ? null : hash('sha256', $inhalt);
    }

    public function diskName(): string
    {
        return $this->diskName;
    }
}
