<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Http\UploadedFile;
use Platform\Core\Models\ContextFile;
use Platform\Core\Services\ContextFileService;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Ablage einer von ZAS gelieferten Datei im Core-Dateidienst.
 *
 * Einzige Beruehrung des Datei-Eingangs mit Core und Dateisystem — damit die
 * Entscheidungslogik (ZasEmployeeFileIntake) ohne Container und ohne
 * Filesystem pruefbar bleibt; im Test tritt eine Attrappe an diese Stelle.
 *
 * Der Core-Dienst erwartet ein UploadedFile, wir haben aber nur Bytes aus dem
 * Request-Body. Deshalb der Umweg ueber eine temporaere Datei (mit
 * `test: true`, sonst prueft Symfony auf einen echten HTTP-Upload).
 *
 * Bildvarianten muss hier niemand anstossen: uploadForContext wandelt Bilder
 * nach WebP und stellt den Varianten-Job selbst in die Queue. Das setzt einen
 * laufenden Queue-Worker voraus — ohne den bleiben die Kaertchen beim Original.
 */
class ZasEmployeeFileStore
{
    public function __construct(private ContextFileService $files) {}

    /**
     * Legt die Datei am Mitarbeiter ab und gibt die File-ID zurueck.
     *
     * Schreibt NICHT die Slot-Spalte — das tut der Intake, und zwar
     * observer-frei. Diese Trennung ist Absicht: die Spalte ist die Stelle,
     * an der ein Rueckexport ausgeloest werden koennte.
     */
    public function create(RecEmployee $employee, string $bytes, string $filename, string $mime): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zas-file-');
        if ($tmp === false) {
            throw new \RuntimeException('Kein temporaerer Dateiname verfuegbar.');
        }

        try {
            file_put_contents($tmp, $bytes);

            $result = $this->files->uploadForContext(
                new UploadedFile($tmp, $filename, $mime, null, true),
                'rec_employee',
                (int) $employee->id,
                [
                    'team_id' => (int) $employee->team_id,
                    // Kein Benutzer: die Datei kommt maschinell von ZAS.
                    'user_id' => null,
                ]
            );

            return (int) $result['id'];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Originalname der Datei, die aktuell in einem Slot liegt — Grundlage der
     * Wiederholungserkennung. null, wenn es die Datei nicht (mehr) gibt.
     */
    public function originalNameOf(?int $fileId): ?string
    {
        if (!$fileId) {
            return null;
        }

        $name = ContextFile::query()->whereKey($fileId)->value('original_name');

        return $name === null ? null : (string) $name;
    }
}
