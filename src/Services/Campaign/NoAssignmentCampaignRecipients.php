<?php

namespace Platform\Recruiting\Services\Campaign;

use Platform\Recruiting\Models\RecApplicant;
use Platform\Recruiting\Models\RecAutoPilotLog;
use Platform\Recruiting\Services\Statistics\EinsatzLookup;

/**
 * Zeilen des Sammelversands „ohne Einsatz": Anzeige-Daten plus die Antwort auf
 * „darf/soll diese Person angeschrieben werden?".
 *
 * ZWEIMAL gefragt, und das ist der Zweck: einmal beim Oeffnen des Modals (fuer
 * Auswahl und Vorauswahl) und noch einmal im Job, unmittelbar vor dem Senden.
 * Zwischen beiden liegen Sekunden bis Minuten, in denen eine Disposition
 * eintreffen kann — „wir haben nichts mehr von dir gehoert" an jemanden, der
 * naechste Woche arbeitet, ist genau die Peinlichkeit, die diese zweite Frage
 * verhindert.
 *
 * Gebuendelte Queries (eine pro Tabelle), nicht eine pro Zeile: das Modal
 * haengt an der Statistik-Seite, deren Query-Budget Abnahmekriterium ist.
 * Team-fremde Bewerbungen tauchen gar nicht erst auf (forTeam ist das aeussere
 * Schloss, wie in Statistics\Index::drillApplicants); jeder ANDERE Grund, warum
 * jemand nichts bekommt, steht als Badge an der Zeile statt sie verschwinden zu
 * lassen.
 *
 * Nicht final: der Job haengt sich im Test per anonymer Unterklasse dran.
 */
class NoAssignmentCampaignRecipients
{
    public const LOG_TYPE = NoAssignmentCampaignSender::LOG_TYPE;

    /**
     * @param list<int> $applicantIds
     * @return array<int, array{applicant_id:int, name:string, selectable:bool, checked:bool, badges:list<string>}>
     */
    public function load(int $teamId, array $applicantIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $applicantIds)));
        if ($ids === []) {
            return [];
        }

        // KEIN is_active-Filter, und das ist der Kern dieser Zielgruppe: wer es
        // bis zum Mitarbeiter geschafft hat, ist als BEWERBUNG inaktiv
        // (CreateEmployeeFromApplicantService:122 — „raus aus dem Dashboard“).
        // Der Topf „ohne Einsatz“ besteht per Definition aus genau diesen
        // Menschen, sie tragen ja eine Personalnummer. Der aus der
        // Bewerber-Kampagne uebernommene Filter loeschte deshalb jede Zeile:
        // 16 Teilnehmer im Modal, „0 von 0 ausgewaehlt“ (Live-Blick 14.09.).
        //
        // Abgesagte und geparkte werden auch nicht mehr weggefiltert, sondern
        // unten mit Grund gesperrt — eine Zeile, die ohne Erklaerung fehlt,
        // war schon einmal der teuerste Teil dieses Features.
        $applicants = RecApplicant::forTeam($teamId)
            ->whereIn('id', $ids)
            ->with([
                'crmContactLinks.contact.phoneNumbers',
                'employees:id,rec_applicant_id,personnel_number,person_key',
            ])
            ->get()
            ->keyBy('id');

        $einsatz = EinsatzLookup::for($teamId, $applicants);

        $letzterVersand = RecAutoPilotLog::query()
            ->whereIn('rec_applicant_id', $ids)
            ->where('type', self::LOG_TYPE)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('rec_applicant_id')
            ->map(fn ($logs) => $logs->first());

        $rows = [];
        foreach ($ids as $id) {
            $a = $applicants->get($id);
            if ($a === null) {
                continue;
            }

            $badges = [];
            $selectable = true;

            // HR-Desk-Zeilen bleiben ansprechbar (kein Ausschlussgrund, Muster
            // NewDatesCampaignRecipients); eine Absage ist einer.
            if ($a->rejected_at !== null) {
                $badges[] = 'abgesagt';
                $selectable = false;
            }
            if ((bool) $a->is_parked) {
                $badges[] = 'geparkt';
                $selectable = false;
            }

            if ($a->primaryContactPhone() === null) {
                $badges[] = 'keine Telefonnummer';
                $selectable = false;
            }

            // NUR ein nachgewiesener Einsatz sperrt. „Nicht pruefbar" (kein MA,
            // keine PersNr) bleibt waehlbar: da ist erst recht niemand
            // gestartet, und die Nachfrage ist genau fuer diese Menschen.
            if ($einsatz->flag($id) === EinsatzLookup::FLAG_DEPLOYED) {
                $badges[] = 'inzwischen im Einsatz';
                $selectable = false;
            }

            $letzter = $letzterVersand->get($id);
            if ($letzter !== null) {
                $badges[] = 'angeschrieben ' . $letzter->created_at?->format('d.m.Y');
            }

            $rows[$id] = [
                'applicant_id' => (int) $a->id,
                'name' => $this->displayName($a),
                'selectable' => $selectable,
                // Vorauswahl endet beim schon Angeschriebenen: ihn ein zweites
                // Mal anzuschreiben soll eine Entscheidung sein, kein Versehen.
                'checked' => $selectable && $letzter === null,
                'badges' => $badges,
            ];
        }

        return $rows;
    }

    /**
     * Name aus Vor-/Nachname statt ueber den full_name-Accessor: der Accessor
     * laedt academicTitle/salutation nach — ohne die im Eager-Load waere das
     * ein N+1 pro Zeile (Muster NewDatesCampaignRecipients).
     */
    private function displayName(RecApplicant $a): string
    {
        $contact = $a->crmContactLinks->sortBy('contact_id')->first()?->contact;
        $name = trim(((string) $contact?->first_name) . ' ' . ((string) $contact?->last_name));

        return $name !== '' ? $name : ('Bewerber #' . $a->id);
    }
}
