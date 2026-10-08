<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentUploadRegeln;
use Platform\Recruiting\Support\EmpfaengerEntdoppler;
use Symfony\Component\Uid\UuidV7;

/**
 * Bereitstellen, erneut senden, zurueckziehen (Spec 2026-10-08, §3.1, §4, §7).
 * EIN Service fuer alle drei Einstiege (Akte, Veranstaltung, Seite Dokumente).
 *
 * Reihenfolge beim Bereitstellen: pruefen → Datei ablegen → Empfaenger
 * entdoppeln → Transaktion (Dokument + Zustellungen) → AUSSERHALB der
 * Transaktion je Empfaenger die WhatsApp. Ein Meta-Fehler kostet einen
 * Empfaenger, nie das Dokument. Nie eine Schreibung auf rec_employees.
 */
class DokumentService
{
    /**
     * Nullable mit faulen Defaults: app(DokumentService::class) baut ihn ohne
     * Argumente, Tests uebergeben Speicher und Sender ausdruecklich.
     */
    public function __construct(
        private ?DokumentSpeicher $speicher = null,
        private ?DokumentHinweisSender $sender = null,
    ) {
    }

    private function speicher(): DokumentSpeicher
    {
        return $this->speicher ??= DokumentSpeicher::default();
    }

    private function sender(): DokumentHinweisSender
    {
        return $this->sender ??= app(DokumentHinweisSender::class);
    }

    /**
     * @param  array{title:string, category:string, action:string} $daten
     * @param  list<int> $employeeIds
     * @return array{dokument: RecDocument, empfaenger: int, versand_noetig: bool}
     * @throws \InvalidArgumentException mit dem Fehlertext fuer HR
     */
    public function bereitstellen(int $teamId, array $daten, string $pdfInhalt, string $originalName, array $employeeIds, ?int $eventId, ?int $userId): array
    {
        $titel = trim((string) ($daten['title'] ?? ''));
        $kategorie = (string) ($daten['category'] ?? '');
        $aktion = (string) ($daten['action'] ?? '');

        if ($titel === '') {
            throw new \InvalidArgumentException('Bitte einen Titel angeben.');
        }
        if (!DokumentKategorie::exists($kategorie)) {
            throw new \InvalidArgumentException('Unbekannte Kategorie.');
        }
        if (!DokumentKategorie::aktionExists($aktion)) {
            throw new \InvalidArgumentException('Unbekannte Aktion.');
        }
        $dateiFehler = DokumentUploadRegeln::pruefe($pdfInhalt, $originalName);
        if ($dateiFehler !== null) {
            throw new \InvalidArgumentException($dateiFehler);
        }

        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        $anstellungen = $ids === [] ? [] : DB::table('rec_employees')
            ->where('team_id', $teamId)
            ->whereIn('id', $ids)
            ->get(['id', 'person_key'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'person_key' => $r->person_key])
            ->keyBy('id')
            ->all();
        // Reihenfolge der Eingabe behalten, fremde/unbekannte ids stumm uebergehen.
        $geordnet = [];
        foreach ($ids as $id) {
            if (isset($anstellungen[$id])) {
                $geordnet[] = $anstellungen[$id];
            }
        }
        $empfaengerIds = EmpfaengerEntdoppler::aufPersonen($geordnet);
        if ($empfaengerIds === []) {
            throw new \InvalidArgumentException('Bitte mindestens einen Empfänger wählen.');
        }

        $uuid = (string) UuidV7::generate();
        $ablage = $this->speicher()->ablegen($teamId, $uuid, $pdfInhalt);

        $dokument = DB::transaction(function () use ($teamId, $uuid, $titel, $kategorie, $aktion, $ablage, $originalName, $eventId, $userId, $empfaengerIds, $anstellungen) {
            $dokument = RecDocument::create([
                'team_id'            => $teamId,
                'uuid'               => $uuid,
                'title'              => mb_substr($titel, 0, 255),
                'category'           => $kategorie,
                'action'             => $aktion,
                'disk'               => $ablage['disk'],
                'stored_path'        => $ablage['stored_path'],
                'original_filename'  => mb_substr($originalName, 0, 255),
                'file_sha256'        => $ablage['sha256'],
                'file_size'          => $ablage['size'],
                'rec_dispo_event_id' => $eventId,
                'created_by_user_id' => $userId,
            ]);
            foreach ($empfaengerIds as $employeeId) {
                RecDocumentRecipient::create([
                    'team_id'         => $teamId,
                    'rec_document_id' => $dokument->id,
                    'rec_employee_id' => $employeeId,
                    'person_key'      => $anstellungen[$employeeId]['person_key'] ?? null,
                ]);
            }

            return $dokument;
        });

        return [
            'dokument'      => $dokument->fresh(),
            'empfaenger'    => count($empfaengerIds),
            'versand_noetig' => DokumentKategorie::brauchtHandlung($aktion),
        ];
    }

    /**
     * Die WhatsApps zu einem Dokument — aus dem Job, nie aus dem Klick: bei
     * 200 Eingebuchten sind das Minuten, und ein Timeout mittendrin liesse
     * die Haelfte stumm. Versucht wird NUR, was noch nie versucht wurde
     * (notified_at UND notify_error leer); ein Fehlschlag wird nicht von
     * selbst wiederholt, dafuer gibt es "Erneut senden". Zurueckgezogene und
     * "nur ablegen" bekommen nichts.
     *
     * @return array{versucht:int, benachrichtigt:int, fehler:array<string,int>}
     */
    public function hinweiseVersenden(RecDocument $dokument): array
    {
        $e = ['versucht' => 0, 'benachrichtigt' => 0, 'fehler' => []];
        if (!DokumentKategorie::brauchtHandlung((string) $dokument->action)) {
            return $e;
        }
        $offene = RecDocumentRecipient::query()
            ->where('rec_document_id', $dokument->id)
            ->whereNull('notified_at')
            ->whereNull('notify_error')
            ->whereNull('withdrawn_at')
            ->orderBy('id')
            ->get();
        foreach ($offene as $empfaenger) {
            $status = $this->hinweisSenden($empfaenger);
            $e['versucht']++;
            if (DokumentHinweisSender::istErfolg($status)) {
                $e['benachrichtigt']++;
            } else {
                $e['fehler'][$status] = ($e['fehler'][$status] ?? 0) + 1;
            }
        }

        return $e;
    }

    /** Schickt den Hinweis und schreibt das Ergebnis an die Zustellung (Query Builder). */
    private function hinweisSenden(RecDocumentRecipient $empfaenger): string
    {
        $employee = RecEmployee::find($empfaenger->rec_employee_id);
        $status = $employee === null ? DokumentHinweisSender::STATUS_FAILED : $this->sender()->sende($employee);

        DB::table('rec_document_recipients')->where('id', $empfaenger->id)->update(
            DokumentHinweisSender::istErfolg($status)
                ? ['notified_at' => now(), 'notify_error' => null, 'updated_at' => now()]
                : ['notify_error' => mb_substr($status, 0, 120), 'updated_at' => now()]
        );

        return $status;
    }
}
