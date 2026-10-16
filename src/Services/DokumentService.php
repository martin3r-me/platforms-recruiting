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
use Platform\Recruiting\Support\DokumentVerlauf;
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
     * Haken "WhatsApp-Hinweis senden" (Sebastian 16.10.2026) je Dokument:
     * whatsapp = wie bisher, none = HR wollte bewusst keine Nachricht. Gilt
     * zusaetzlich zur Kategorie-Regel (DokumentKategorie::brauchtHinweis).
     */
    public const NOTIFY_WHATSAPP = 'whatsapp';
    public const NOTIFY_NONE     = 'none';

    /** Status von erneutSenden() bei notify_mode none — ein Zustand, kein Fehler. */
    public const STATUS_OHNE_WHATSAPP = 'ohne_whatsapp';

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
     * Die EINE Pruefung "bekommt dieses Dokument die Portal-WhatsApp?":
     * Kategorie-Regel UND Haken. Service, Job, Akte-Zeilen und Ueberblick
     * fragen hier — sonst schriebe einer der Wege trotz "ohne WhatsApp" an.
     * Ein fehlender Wert (Bestand vor der Spalte) zaehlt als whatsapp.
     */
    public static function hinweisFaellig(string $category, string $action, ?string $notifyMode): bool
    {
        return DokumentKategorie::brauchtHinweis($category, $action) && $notifyMode !== self::NOTIFY_NONE;
    }

    /** Nur die zwei bekannten Werte; fehlt der Schluessel, gilt whatsapp (Bestandsverhalten). */
    private static function notifyMode(array $daten): string
    {
        $mode = (string) ($daten['notify_mode'] ?? self::NOTIFY_WHATSAPP);
        if (!in_array($mode, [self::NOTIFY_WHATSAPP, self::NOTIFY_NONE], true)) {
            throw new \InvalidArgumentException('Unbekannte Benachrichtigungsart.');
        }

        return $mode;
    }

    /** Von wo bereitgestellt wurde (Verlauf): dokumente | akte | veranstaltung, Default dokumente. */
    private static function einstieg(array $daten): string
    {
        $e = (string) ($daten['einstieg'] ?? 'dokumente');

        return in_array($e, ['dokumente', 'akte', 'veranstaltung'], true) ? $e : 'dokumente';
    }

    /**
     * Angemeldeter HR-User fuer den Verlauf; im Job, in der Konsole und in
     * Tests ohne Anmeldung null ("System").
     */
    private static function benutzer(): ?int
    {
        try {
            $id = auth()->id();
        } catch (\Throwable) {
            return null;
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array{title:string, category:string, action:string, notify_mode?:string, einstieg?:string} $daten
     * @param  list<int> $employeeIds
     * @return array{dokument: RecDocument, empfaenger: int, versand_noetig: bool}
     * @throws \InvalidArgumentException mit dem Fehlertext fuer HR
     */
    public function bereitstellen(int $teamId, array $daten, string $pdfInhalt, string $originalName, array $employeeIds, ?int $eventId, ?int $userId): array
    {
        $titel = trim((string) ($daten['title'] ?? ''));
        $kategorie = (string) ($daten['category'] ?? '');
        $aktion = (string) ($daten['action'] ?? '');
        $notifyMode = self::notifyMode($daten);

        if ($titel === '') {
            throw new \InvalidArgumentException('Bitte einen Titel angeben.');
        }
        if (!DokumentKategorie::exists($kategorie)) {
            throw new \InvalidArgumentException('Unbekannte Kategorie.');
        }
        if (DokumentKategorie::istText($kategorie)) {
            throw new \InvalidArgumentException('Infos werden als Text bereitgestellt, nicht als PDF.');
        }
        if (!DokumentKategorie::aktionExists($aktion)) {
            throw new \InvalidArgumentException('Unbekannte Aktion.');
        }
        $dateiFehler = DokumentUploadRegeln::pruefe($pdfInhalt, $originalName);
        if ($dateiFehler !== null) {
            throw new \InvalidArgumentException($dateiFehler);
        }

        $aufgeloest = $this->empfaengerAufloesen($teamId, $employeeIds);
        $empfaengerIds = $aufgeloest['ids'];
        $anstellungen = $aufgeloest['anstellungen'];
        if ($empfaengerIds === []) {
            throw new \InvalidArgumentException('Bitte mindestens einen Empfänger wählen.');
        }

        $uuid = (string) UuidV7::generate();
        $ablage = $this->speicher()->ablegen($teamId, $uuid, $pdfInhalt);

        $dokument = DB::transaction(function () use ($teamId, $uuid, $titel, $kategorie, $aktion, $notifyMode, $ablage, $originalName, $eventId, $userId, $empfaengerIds, $anstellungen) {
            $dokument = RecDocument::create([
                'team_id'            => $teamId,
                'uuid'               => $uuid,
                'title'              => mb_substr($titel, 0, 255),
                'category'           => $kategorie,
                'action'             => $aktion,
                'notify_mode'        => $notifyMode,
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

        // Nach der Transaktion: ein Verlaufsfehler darf das Dokument nicht zurueckrollen.
        DokumentVerlauf::schreibe($teamId, (int) $dokument->id, null, DokumentVerlauf::BEREITGESTELLT, $userId, [
            'empfaenger'            => count($empfaengerIds),
            'notify_mode'           => $notifyMode,
            'auto_neue_mitarbeiter' => false,
            'art'                   => 'pdf',
            'einstieg'              => self::einstieg($daten),
        ]);

        return [
            'dokument'      => $dokument->fresh(),
            'empfaenger'    => count($empfaengerIds),
            'versand_noetig' => DokumentKategorie::brauchtHandlung($aktion) && $notifyMode === self::NOTIFY_WHATSAPP,
        ];
    }

    public const INFO_MAX_ZEICHEN = 20000;

    /**
     * Info/News als Text statt PDF (Spec 2026-10-16 §3.1). Kategorie info,
     * Aktion none, Pruefsumme ueber den Text in file_sha256. Ohne Empfaenger
     * nur mit auto_neue_mitarbeiter — sonst laege eine Info, die nie jemand
     * sieht. Keine Datei, kein Speicher.
     *
     * @param  array{title:string, auto_neue_mitarbeiter?:bool, notify_mode?:string, einstieg?:string} $daten
     * @param  list<int> $employeeIds
     * @return array{dokument: RecDocument, empfaenger: int, versand_noetig: bool}
     */
    public function bereitstellenText(int $teamId, array $daten, string $markdown, array $employeeIds, ?int $userId): array
    {
        $titel = trim((string) ($daten['title'] ?? ''));
        $auto = (bool) ($daten['auto_neue_mitarbeiter'] ?? false);
        $notifyMode = self::notifyMode($daten);
        if ($titel === '') {
            throw new \InvalidArgumentException('Bitte einen Titel angeben.');
        }
        if (trim($markdown) === '') {
            throw new \InvalidArgumentException('Bitte einen Text schreiben.');
        }
        if (mb_strlen($markdown) > self::INFO_MAX_ZEICHEN) {
            throw new \InvalidArgumentException('Der Text ist zu lang (höchstens ' . self::INFO_MAX_ZEICHEN . ' Zeichen).');
        }
        $aufgeloest = $this->empfaengerAufloesen($teamId, $employeeIds);
        if ($aufgeloest['ids'] === [] && !$auto) {
            throw new \InvalidArgumentException('Bitte mindestens einen Empfänger wählen oder „automatisch an neue Mitarbeiter“ anhaken.');
        }

        $dokument = DB::transaction(function () use ($teamId, $titel, $markdown, $auto, $notifyMode, $userId, $aufgeloest) {
            $dokument = RecDocument::create([
                'team_id'               => $teamId,
                'uuid'                  => (string) UuidV7::generate(),
                'title'                 => mb_substr($titel, 0, 255),
                'category'              => DokumentKategorie::INFO,
                'action'                => DokumentKategorie::AKTION_NONE,
                'notify_mode'           => $notifyMode,
                'content_markdown'      => $markdown,
                'file_sha256'           => hash('sha256', $markdown),
                'auto_neue_mitarbeiter' => $auto,
                'published_at'          => now(),
                'created_by_user_id'    => $userId,
            ]);
            foreach ($aufgeloest['ids'] as $employeeId) {
                RecDocumentRecipient::create([
                    'team_id'         => $teamId,
                    'rec_document_id' => $dokument->id,
                    'rec_employee_id' => $employeeId,
                    'person_key'      => $aufgeloest['anstellungen'][$employeeId]['person_key'] ?? null,
                ]);
            }

            return $dokument;
        });

        DokumentVerlauf::schreibe($teamId, (int) $dokument->id, null, DokumentVerlauf::BEREITGESTELLT, $userId, [
            'empfaenger'            => count($aufgeloest['ids']),
            'notify_mode'           => $notifyMode,
            'auto_neue_mitarbeiter' => $auto,
            'art'                   => 'info',
            'einstieg'              => self::einstieg($daten),
        ]);

        // Mit Empfaengern geht die Portal-WhatsApp raus wie bei einer PDF (Spec §1.3, §7);
        // ohne (nur Automatik) gibt es niemanden anzuschreiben, ohne Haken soll niemand.
        return [
            'dokument'       => $dokument->fresh(),
            'empfaenger'     => count($aufgeloest['ids']),
            'versand_noetig' => $aufgeloest['ids'] !== [] && $notifyMode === self::NOTIFY_WHATSAPP,
        ];
    }

    /**
     * Team-gepruefte, entdoppelte Empfaenger (Eingabereihenfolge bleibt,
     * fremde/unbekannte ids stumm uebergangen). Gemeinsam fuer PDF und Text.
     *
     * @param  list<int> $employeeIds
     * @return array{ids: list<int>, anstellungen: array<int, array{id:int, person_key:?string}>}
     */
    private function empfaengerAufloesen(int $teamId, array $employeeIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        $anstellungen = $ids === [] ? [] : DB::table('rec_employees')
            ->where('team_id', $teamId)
            ->whereIn('id', $ids)
            ->get(['id', 'person_key'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'person_key' => $r->person_key])
            ->keyBy('id')
            ->all();
        $geordnet = [];
        foreach ($ids as $id) {
            if (isset($anstellungen[$id])) {
                $geordnet[] = $anstellungen[$id];
            }
        }

        return ['ids' => EmpfaengerEntdoppler::aufPersonen($geordnet), 'anstellungen' => $anstellungen];
    }

    /**
     * Die WhatsApps zu einem Dokument — aus dem Job, nie aus dem Klick: bei
     * 200 Eingebuchten sind das Minuten, und ein Timeout mittendrin liesse
     * die Haelfte stumm. Versucht wird NUR, was noch nie versucht wurde
     * (notified_at UND notify_error leer); ein Fehlschlag wird nicht von
     * selbst wiederholt, dafuer gibt es "Erneut senden". Zurueckgezogene und
     * "nur ablegen" bekommen nichts, ein Dokument "ohne WhatsApp" auch nicht.
     *
     * Schon Erledigte (unterschrieben/bestaetigt, Info gesehen) bekommen
     * nichts — dieselbe Pruefung wie "Erneut senden". Relevant vor allem nach
     * "WhatsApp jetzt senden": zwischen Bereitstellen ohne WhatsApp und dem
     * Umdrehen koennen Leute laengst fertig sein (Review W1). Sie werden
     * weder gestempelt noch als Fehler gezaehlt (uebersprungen_erledigt).
     *
     * @return array{versucht:int, benachrichtigt:int, fehler:array<string,int>, uebersprungen_erledigt:int}
     */
    public function hinweiseVersenden(RecDocument $dokument): array
    {
        $e = ['versucht' => 0, 'benachrichtigt' => 0, 'fehler' => [], 'uebersprungen_erledigt' => 0];
        if (!self::hinweisFaellig((string) $dokument->category, (string) $dokument->action, $this->frischerNotifyMode((int) $dokument->id))) {
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
            if (DokumentStatus::hinweisErledigt($empfaenger->zeitstempel(), (string) $dokument->category, (string) $dokument->action)) {
                $e['uebersprungen_erledigt']++;

                continue;
            }
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
        $status = $this->erneutSendenOhneVerlauf($empfaenger);
        // Jeder Klick, auch ein abgewiesener: der Verlauf soll zeigen, dass HR es versucht hat.
        DokumentVerlauf::schreibe((int) $empfaenger->team_id, (int) $empfaenger->rec_document_id, (int) $empfaenger->id,
            DokumentVerlauf::ERNEUT_GESENDET, self::benutzer(), ['status' => $status]);

        return $status;
    }

    private function erneutSendenOhneVerlauf(RecDocumentRecipient $empfaenger): string
    {
        if ($empfaenger->withdrawn_at !== null) {
            return 'zurueckgezogen';
        }
        $dok = DB::table('rec_documents')->where('id', $empfaenger->rec_document_id)->first(['category', 'action', 'notify_mode']);
        $kategorie = (string) ($dok->category ?? '');
        $aktion = (string) ($dok->action ?? '');
        if (!DokumentKategorie::brauchtHinweis($kategorie, $aktion)) {
            return 'nur_ablegen';
        }
        // HR hat "ohne WhatsApp" gewaehlt: Umdrehen geht nur bewusst ueber "WhatsApp jetzt senden".
        if (!self::hinweisFaellig($kategorie, $aktion, $dok->notify_mode ?? null)) {
            return self::STATUS_OHNE_WHATSAPP;
        }
        // Automatisch zugestellte Info: wird nie angeschrieben (Spec §7).
        if ($empfaenger->notify_error === DokumentHinweisSender::STATUS_NICHT_NOETIG) {
            return DokumentHinweisSender::STATUS_NICHT_NOETIG;
        }
        // Schon unterschrieben/bestaetigt bzw. Info schon gesehen: keine Erinnerung mehr an Erledigtes.
        if (DokumentStatus::hinweisErledigt($empfaenger->zeitstempel(), $kategorie, $aktion)) {
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
        $getan = DB::table('rec_document_recipients')
            ->where('id', $empfaenger->id)
            ->whereNull('signed_at')
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now(), 'updated_at' => now()]);
        // Nur wenn wirklich etwas zurueckgezogen wurde (Doppelklick = eine Zeile).
        if ($getan > 0) {
            DokumentVerlauf::schreibe((int) $empfaenger->team_id, (int) $empfaenger->rec_document_id, (int) $empfaenger->id,
                DokumentVerlauf::ZURUECKGEZOGEN, self::benutzer());
        }

        return null;
    }

    /**
     * Knopf "WhatsApp jetzt senden": HR dreht ein Dokument "ohne WhatsApp"
     * bewusst um. Nur bei none, nicht zurueckgezogen und nur, wenn die
     * Kategorie ueberhaupt eine Nachricht kennt ("nur ablegen" hat keine).
     * Den Versand startet der Aufrufer ueber DokumentHinweiseVersenden —
     * dieselbe Regel wie beim Bereitstellen. true = umgedreht.
     */
    public function whatsappJetzt(RecDocument $dokument, ?int $userId): bool
    {
        if (!DokumentKategorie::brauchtHinweis((string) $dokument->category, (string) $dokument->action)) {
            return false;
        }
        $gedreht = DB::table('rec_documents')
            ->where('id', $dokument->id)
            ->where('notify_mode', self::NOTIFY_NONE)
            ->whereNull('deleted_at')
            ->update(['notify_mode' => self::NOTIFY_WHATSAPP, 'updated_at' => now()]) > 0;
        if ($gedreht) {
            DokumentVerlauf::schreibe((int) $dokument->team_id, (int) $dokument->id, null, DokumentVerlauf::WHATSAPP_JETZT, $userId);
        }

        return $gedreht;
    }

    /**
     * Ganzes Dokument zurueckziehen (Spec §7): alle offenen Zustellungen, dann
     * SoftDelete nur, wenn NIEMAND unterschrieben hat. Mit Unterschriften bleibt
     * das Dokument als Nachweis stehen ("teilweise zurueckgezogen").
     * Zusaetzlich faellt der Automatik-Haken (Spec 2026-10-16 §3.3) — sonst
     * bekaeme der naechste Neue eine zurueckgezogene Info. Das SoftDelete
     * allein reicht nicht: mit Unterschriften bleibt das Dokument stehen.
     *
     * @return array{zurueckgezogen:int, geloescht:bool}
     */
    public function dokumentZurueckziehen(RecDocument $dokument): array
    {
        // Der Haken faellt still mit — der Verlauf haelt "ganz zurueckgezogen" fest, das schliesst ihn ein.
        $this->automatikHakenNehmen($dokument);

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
        DokumentVerlauf::schreibe((int) $dokument->team_id, (int) $dokument->id, null, DokumentVerlauf::ZURUECKGEZOGEN, self::benutzer(), [
            'anzahl'    => (int) $zurueck,
            'geloescht' => !$unterschrieben,
        ]);

        return ['zurueckgezogen' => (int) $zurueck, 'geloescht' => !$unterschrieben];
    }

    /**
     * Nur den Automatik-Haken einer Info nehmen (Spec 2026-10-16 §6): keine
     * neuen Zustellungen mehr an Neue, wer die Info schon hat, behaelt sie.
     * Query Builder, damit kein Modell-Hook mitlaeuft. true = Haken war gesetzt.
     */
    public function automatikBeenden(RecDocument $dokument): bool
    {
        $beendet = $this->automatikHakenNehmen($dokument);
        if ($beendet) {
            DokumentVerlauf::schreibe((int) $dokument->team_id, (int) $dokument->id, null, DokumentVerlauf::AUTOMATIK_BEENDET, self::benutzer());
        }

        return $beendet;
    }

    private function automatikHakenNehmen(RecDocument $dokument): bool
    {
        return DB::table('rec_documents')
            ->where('id', $dokument->id)
            ->where('auto_neue_mitarbeiter', true)
            ->update(['auto_neue_mitarbeiter' => false, 'updated_at' => now()]) > 0;
    }

    /**
     * notify_mode frisch aus der Tabelle: der Job haelt das Dokument eine
     * Weile in der Hand, und das Modell eines Aufrufers kann vor dem
     * Umdrehen geladen worden sein.
     */
    private function frischerNotifyMode(int $documentId): ?string
    {
        $mode = DB::table('rec_documents')->where('id', $documentId)->value('notify_mode');

        return $mode === null ? null : (string) $mode;
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
