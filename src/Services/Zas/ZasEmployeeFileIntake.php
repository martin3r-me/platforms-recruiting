<?php

namespace Platform\Recruiting\Services\Zas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Recruiting\Support\ZasImageContent;
use Platform\Recruiting\Support\ZasInboundFileSlots;
use Platform\Recruiting\Support\ZasPersonnelNumber;

/**
 * Nimmt eine einzelne Datei von ZAS an und legt sie in einen Dokumentslot.
 *
 * Anlass: die ~1100 Bestands-Mitarbeiter haben kein Selfie, und fuer sie ist
 * ZAS die einzige Quelle ausser dem Portal — in den Crew-Kaertchen sieht ein
 * Teamleiter bei 85 % der Belegschaft kein Gesicht. Die CSV liefert seit dem
 * 04.09. den Dateinamen (99 von 100 Zeilen), aber ein Name ist kein Bild;
 * ZAS schickt die Datei deshalb einzeln an diesen Eingang.
 *
 * Zwei Zusicherungen, die ZAS schriftlich hat und die hier eingehalten werden:
 *
 *  - Wiederholtes Senden derselben Datei ist unschaedlich (`already_present`),
 *    damit eine Schleife ueber den gesamten Bestand beliebig oft laufen kann.
 *  - Ein gefuellter Slot wird NIE ueberschrieben. Was HR oder der Mitarbeiter
 *    selbst hochgeladen hat, gewinnt gegen die Altdaten aus ZAS.
 *
 * Angelegt wird nichts: eine unbekannte Personalnummer ist ein Fehler, kein
 * Anlass fuer einen neuen Mitarbeiter.
 */
class ZasEmployeeFileIntake
{
    public function __construct(
        private ZasEmployeeMatcher $matcher,
        private ZasEmployeeFileStore $store,
    ) {}

    /**
     * @param  string      $personnelNumber ZAS-Personalnummer, blank oder mit Praefix
     * @param  string      $slot            Slot-Name wie beim Abruf (`emp-selfie`)
     * @param  string      $bytes           Dateiinhalt
     * @param  string|null $filename        Originalname, optional
     * @return array{status: string, http: int, message: string, employee_id: ?int, file_id: ?int, matched_via: ?string}
     */
    public function receive(string $personnelNumber, string $slot, string $bytes, ?string $filename): array
    {
        // Reihenfolge mit Absicht: erst die billigen Pruefungen, dann die DB.
        $column = ZasInboundFileSlots::columnFor($slot);
        if ($column === null) {
            return $this->result('slot_not_allowed', 422, "Slot '{$slot}' ist fuer den Eingang nicht freigegeben.");
        }

        if ($bytes === '') {
            return $this->result('empty', 422, 'Kein Dateiinhalt empfangen.');
        }

        if (ZasImageContent::exceedsLimit($bytes)) {
            return $this->result('too_large', 413, sprintf(
                'Datei ist %d Bytes gross, erlaubt sind %d.',
                strlen($bytes),
                ZasImageContent::MAX_BYTES
            ));
        }

        // Am Inhalt geprueft, nicht an der Endung: in der Testlieferung vom
        // 03.09. stand ein Hallenplan im Selfie-Feld.
        $mime = ZasImageContent::mimeOf($bytes);
        if ($mime === null) {
            return $this->result('not_an_image', 415, 'Inhalt ist kein akzeptiertes Bild (erlaubt: JPEG, PNG).');
        }

        $prefix     = (string) config('recruiting.zas.company_prefix', '');
        $teamId     = config('recruiting.zas.inbound_team_id');
        $normalized = ZasPersonnelNumber::normalize($personnelNumber, $prefix);

        if ($normalized === null) {
            return $this->result('personnel_number_missing', 422, 'Personalnummer fehlt.');
        }

        $match    = $this->matcher->match(null, $normalized, $teamId, $prefix);
        $employee = $match['employee'];

        if ($employee === null) {
            return $this->result('not_found', 404, "Personalnummer {$normalized} ist bei uns nicht bekannt.");
        }

        $name    = $this->filename($filename, $normalized, $mime);
        $current = $employee->{$column};

        if ($current) {
            $existing = $this->store->originalNameOf((int) $current);

            if ($existing === $name) {
                return $this->result(
                    'already_present',
                    200,
                    'Diese Datei liegt bereits vor.',
                    $employee->id,
                    (int) $current,
                    $match['via']
                );
            }

            if ($existing !== null) {
                return $this->result(
                    'slot_filled',
                    409,
                    "Slot ist bereits mit '{$existing}' belegt und wird nicht ueberschrieben.",
                    $employee->id,
                    (int) $current,
                    $match['via']
                );
            }

            // Verweis ins Leere: die Spalte zeigt auf eine Datei, die es nicht
            // mehr gibt. Der Slot ist faktisch leer, also darf er gefuellt
            // werden — sonst koennte dieser Mitarbeiter nie wieder ein Bild
            // bekommen, und niemand wuerde es merken.
            Log::info('ZAS-Datei-Eingang: Slot zeigte auf eine fehlende Datei, wird neu belegt', [
                'employee_id' => $employee->id,
                'slot'        => $slot,
                'old_file_id' => (int) $current,
            ]);
        }

        $fileId = $this->store->create($employee, $bytes, $name, $mime);

        // Observer-frei: selfie_file_id steht in der Watch-Liste des
        // RecEmployeeExportObservers. Normal geschrieben wuerde `zas_changed_at`
        // gesetzt und wir schickten ZAS eine signierte URL auf das Bild
        // zurueck, das ZAS uns gerade gegeben hat. Ein bereits gesetzter Marker
        // bleibt unangetastet — er stammt aus einer echten HR-Aenderung.
        DB::table('rec_employees')->where('id', $employee->id)->update([$column => $fileId]);

        Log::info('ZAS-Datei-Eingang: Datei uebernommen', [
            'employee_id'      => $employee->id,
            'personnel_number' => $normalized,
            'slot'             => $slot,
            'file_id'          => $fileId,
            'matched_via'      => $match['via'],
            'bytes'            => strlen($bytes),
        ]);

        return $this->result('stored', 201, 'Datei uebernommen.', $employee->id, $fileId, $match['via']);
    }

    /**
     * Originalname, entschaerft: nur der Dateiname, keine Pfadanteile — ZAS
     * liefert Verweise wie `1187/Selfie-x.jpg`. Ohne brauchbaren Namen wird
     * einer gebildet, damit die Wiederholungserkennung etwas zu vergleichen hat.
     */
    private function filename(?string $raw, string $personnelNumber, string $mime): string
    {
        $extension = $mime === 'image/png' ? 'png' : 'jpg';
        $name      = basename(str_replace('\\', '/', trim((string) $raw)));
        $name      = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name      = trim($name, ". \t");

        if ($name === '') {
            return "selfie-{$personnelNumber}.{$extension}";
        }

        return mb_substr($name, 0, 120);
    }

    /**
     * @return array{status: string, http: int, message: string, employee_id: ?int, file_id: ?int, matched_via: ?string}
     */
    private function result(
        string $status,
        int $http,
        string $message,
        ?int $employeeId = null,
        ?int $fileId = null,
        ?string $matchedVia = null
    ): array {
        return [
            'status'      => $status,
            'http'        => $http,
            'message'     => $message,
            'employee_id' => $employeeId,
            'file_id'     => $fileId,
            'matched_via' => $matchedVia,
        ];
    }
}
