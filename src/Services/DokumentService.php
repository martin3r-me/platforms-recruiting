<?php

namespace Platform\Recruiting\Services;

use Illuminate\Support\Facades\DB;
use Platform\Recruiting\Models\RecDocument;
use Platform\Recruiting\Models\RecDocumentRecipient;
use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Services\Comms\DokumentHinweisSender;
use Platform\Recruiting\Support\DokumentKategorie;
use Platform\Recruiting\Support\DokumentStatus;
use Platform\Recruiting\Support\DokumentUploadRegeln;
use Platform\Recruiting\Support\EmpfaengerEntdoppler;
use Symfony\Component\Uid\UuidV7;

/**
 * Bereitstellen, erneut senden, zurueckziehen (Spec 2026-10-08, §3.1, §4, §7).
 * EIN Service fuer alle drei Einstiege (Akte, Veranstaltung, Seite Dokumente).
 *
 * Reihenfolge beim Bereitstellen: pruefen → Datei ablegen → Empfaenger
 * entdoppeln → Transaktion (Dokument + Zustellungen). Bereitstellen legt NUR
 * Zeilen an; die WhatsApps verschickt der Job DokumentHinweiseVersenden
 * (Queue) ueber hinweiseVersenden(), nie der Klick. Ein Meta-Fehler kostet
 * einen Empfaenger, nie das Dokument. Nie eine Schreibung auf rec_employees.
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

    /**
     * HR-Knopf "Erneut senden" (Spec §4): derselbe Sender, dasselbe Ergebnis
     * an der Zustellung. Kein Versand an Zurueckgezogene, keiner bei "nur
     * ablegen" — beides Zustaende, nicht Fehler.
     */
    public function erneutSenden(RecDocumentRecipient $empfaenger): string
    {
        if ($empfaenger->withdrawn_at !== null) {
            return 'zurueckgezogen';
        }
        $aktion = (string) DB::table('rec_documents')->where('id', $empfaenger->rec_document_id)->value('action');
        if (!DokumentKategorie::brauchtHandlung($aktion)) {
            return 'nur_ablegen';
        }
        // Schon unterschrieben/bestaetigt: keine Erinnerung mehr an Erledigtes.
        if (DokumentStatus::istErledigt($empfaenger->zeitstempel(), $aktion)) {
            return 'erledigt';
        }

        return $this->hinweisSenden($empfaenger);
    }

    /**
     * Zurueckziehen je Empfaenger (Spec §7): Zeitstempel, kein Loeschen.
     * Gesperrt sobald unterschrieben — eine Unterschrift ist ein Nachweis.
     *
     * @return ?string null = zurueckgezogen (oder schon gewesen), sonst Fehlertext
     */
    public function zurueckziehen(RecDocumentRecipient $empfaenger): ?string
    {
        if ($empfaenger->signed_at !== null) {
            return 'Unterschrieben, kann nicht zurückgezogen werden.';
        }
        DB::table('rec_document_recipients')
            ->where('id', $empfaenger->id)
            ->whereNull('signed_at')
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);

        return null;
    }

    /**
     * Ganzes Dokument zurueckziehen (Spec §7): alle offenen Zustellungen, dann
     * SoftDelete nur, wenn NIEMAND unterschrieben hat. Mit Unterschriften bleibt
     * das Dokument als Nachweis stehen ("teilweise zurueckgezogen").
     *
     * @return array{zurueckgezogen:int, geloescht:bool}
     */
    public function dokumentZurueckziehen(RecDocument $dokument): array
    {
        $zurueck = DB::table('rec_document_recipients')
            ->where('rec_document_id', $dokument->id)
            ->whereNull('signed_at')
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);

        $unterschrieben = DB::table('rec_document_recipients')
            ->where('rec_document_id', $dokument->id)
            ->whereNotNull('signed_at')
            ->exists();

        if (!$unterschrieben) {
            $dokument->delete();
        }

        return ['zurueckgezogen' => (int) $zurueck, 'geloescht' => !$unterschrieben];
    }

    /**
     * Schickt den Hinweis und schreibt das Ergebnis an die Zustellung (Query
     * Builder). Die Zeile wird VOR dem Senden frisch gelesen: zwischen dem
     * Laden der Liste im Job und dieser Zeile kann HR zurueckgezogen haben
     * (Final-Review Fund 10). Der Stempel traegt dieselbe Bedingung.
     */
    private function hinweisSenden(RecDocumentRecipient $empfaenger): string
    {
        $frisch = DB::table('rec_document_recipients')->where('id', $empfaenger->id)->first(['withdrawn_at']);
        if ($frisch === null || $frisch->withdrawn_at !== null) {
            return 'zurueckgezogen';
        }
        $employee = RecEmployee::find($empfaenger->rec_employee_id);
        $status = $employee === null ? DokumentHinweisSender::STATUS_FAILED : $this->sender()->sende($employee);

        DB::table('rec_document_recipients')->where('id', $empfaenger->id)->whereNull('withdrawn_at')->update(
            DokumentHinweisSender::istErfolg($status)
                ? ['notified_at' => now(), 'notify_error' => null, 'updated_at' => now()]
                : ['notify_error' => mb_substr($status, 0, 120), 'updated_at' => now()]
        );

        return $status;
    }
}
