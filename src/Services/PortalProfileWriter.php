<?php

namespace Platform\Recruiting\Services;

use Platform\Recruiting\Models\RecEmployee;
use Platform\Recruiting\Support\PortalBoolValue;
use Platform\Recruiting\Support\PortalProfileGuards;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Stammdaten aus dem Mitarbeiterportal speichern — UEBER ELOQUENT.
 *
 * Das ist eine bewusste Entscheidung und der Grund, warum die Beobachter
 * hier anspringen SOLLEN:
 *   N1  ZAS-Export-Marker fuer die relevanten Spalten (§3.2)
 *   N2  Lohn-Trigger fuer die 13 lohnrelevanten Spalten (§3.3), inkl.
 *       is_main_employer
 *   N5  CRM-Telefonabgleich, wenn sich phone aendert (Vorfall RG19734:
 *       ohne ihn ordnet der WhatsApp-Eingang Antworten von der neuen Nummer
 *       keinem Kontakt mehr zu)
 *   N7  Leerraum raus aus steuer_id und sozialversicherungsnummer
 *       (Mutatoren am Modell)
 * Wer hier auf den Query Builder ausweicht, verliert alle vier auf einmal —
 * still, denn nichts davon wirft.
 *
 * Die fuenf Spalten mit Marker-VERBOT (§3.2) stehen schlicht nicht in
 * RecEmployeeExportObserver::RELEVANT_EMPLOYEE_FIELDS — phone,
 * is_main_employer, other_employer, erstbescheinigung_file_id,
 * first_aider_certificate_file_id. Das regelt sich also von allein und
 * braucht hier keine zweite Liste, die auseinanderlaufen koennte. Gemessen
 * wird es einzeln in PortalProfileWriterTest.
 *
 * DATEIEN laufen NICHT hier durch, sondern ueber ProofWriter. Ein
 * 'file'-Typ wird immer uebersprungen (R20/E8).
 */
final class PortalProfileWriter
{
    /**
     * @param  array<string,mixed> $formwerte  rohe wire:model-Werte
     * @param  ?string $nurGruppe  Name der offenen Gruppe; alles ausserhalb
     *                             wird verworfen (Verschaerfung von R19)
     * @return array{ok:bool, fehler:?string, meldung:?string}
     */
    public function speichere(RecEmployee $employee, array $formwerte, ?string $nurGruppe = null): array
    {
        $gruppen = $employee->editableFieldGroups();
        $erlaubt = $employee->editableFieldsFlat();

        // EINE BEDEUTUNG FUER BEIDE HAELFTEN DIESER METHODE. Ohne diese Zeilen
        // lesen sie denselben Wert verschieden:
        //   - die Waechter mit ??  -> ein Schluessel mit Wert null gilt als
        //     NICHT uebergeben und faellt auf den Datensatz zurueck
        //     (?? prueft isset(), und isset(null) ist false),
        //   - die Schreibschleife mit array_key_exists -> derselbe Wert gilt
        //     als uebergeben und schreibt NULL.
        // Ein null kam damit an JEDEM Waechter vorbei und leerte die Spalte:
        // ['nationality' => null] setzte die Staatsangehoerigkeit auf NULL und
        // dazu den ZAS-Marker (die Spalte steht in RELEVANT_EMPLOYEE_FIELDS) —
        // die naechste Aktualisierungsdatei haette den in ZAS gepflegten Wert
        // mit einer leeren Zelle ueberschrieben, waehrend die Oberflaeche
        // "Gespeichert." meldete. Mit '' war alles richtig, deshalb fiel es
        // nicht auf.
        //
        // Ein uebergebener Schluessel heisst: dieses Feld wurde angefasst. Ein
        // leerer Wert heisst: es wurde geleert. Das ist dieselbe Aussage, egal
        // ob sie als null oder als '' ankommt — und eine geleerte
        // Pflichtangabe gehoert VOR den Waechter, nicht an ihm vorbei.
        // BITTE NICHT ALS UEBERFLUESSIG ENTFERNEN.
        foreach ($formwerte as $feld => $wert) {
            if ($wert === null) {
                $formwerte[$feld] = '';
            }
        }

        // Ein unbekannter Gruppenname ist ein FEHLER, kein Nichts. Sonst bliebe
        // die Reichweite leer, es wuerde nichts geschrieben, und die Rueckgabe
        // lautete ok=true, "Keine Änderungen." — eine unauffaellige Meldung,
        // waehrend die eingetippte Steuer-ID verschwindet. Ein Tippfehler
        // reicht: die Gruppe heisst "Steuer & Versicherung", mit einem
        // kaufmaennischen Und.
        //
        // Rueckgabe statt Ausnahme, weil das nicht nur Tippfehler trifft: die
        // Gruppen haengen am Datensatz ("Aufenthalt (Non-EU)" gibt es nur fuer
        // Nicht-EU-Buerger, die Bescheinigungs-Gruppe nur fuer Schueler und
        // Studenten). Aendert HR waehrend einer offenen Seite den Status, ist
        // eine eben noch gerenderte Gruppe verschwunden — dann gehoert dem
        // Menschen eine Meldung hingestellt und kein 500er.
        if ($nurGruppe !== null && !array_key_exists($nurGruppe, $gruppen)) {
            return [
                'ok'      => false,
                'fehler'  => 'Dieser Abschnitt lässt sich gerade nicht speichern — bitte die Seite neu laden. Es wurde nichts gespeichert.',
                'meldung' => null,
            ];
        }

        // Die Gruppengrenze zuerst, dann erst die Waechter: sonst koennte ein
        // manipulierter POST der Kaskade Werte aus einem Blatt unterschieben,
        // das gar nicht offen ist.
        $reichweite = $nurGruppe !== null ? $gruppen[$nurGruppe] : $erlaubt;
        if ($nurGruppe !== null) {
            $formwerte = array_intersect_key($formwerte, $reichweite);
        }

        // F4 (26.09.2026, RULING): DIE FUENF ABLAUFDATEN SIND HIER NICHT
        // SCHREIBBAR. Sie werden VOR den Waechtern aus den Formularwerten
        // entfernt -- nicht erst in der Schreibschleife, sonst koennte ein
        // manipulierter POST der Kaskade ein Datum unterschieben, das
        // anschliessend gar nicht geschrieben wird ("Ersthelfer=Ja" ginge
        // durch, das Datum bliebe leer).
        //
        // identity_card_valid_until, school_certificate_valid_until,
        // first_aider_valid_until, residence_permit_valid_until und
        // work_permit_valid_until sind Profilfeld UND Ablaufspalte einer
        // Nachweisart. Gespiegelt wurde bisher nur in EINE Richtung
        // (ProofWriter: Nachweis -> Spalte). Wer das Datum im Profil aenderte,
        // bekam "Gespeichert.", die Gruppenzeile zeigte das neue Datum, der
        // Ring stieg -- und der Start-Bildschirm sagte WEITERHIN "Abgelaufen
        // am 01.09.2026", dauerhaft, weil die Nachweis-Zeile unberuehrt blieb.
        //
        // WARUM NICHT DIE GEGENRICHTUNG SPIEGELN: die Nachweistabelle soll
        // genau EINEN Schreiber behalten. Zwei Schreibwege auf dieselbe Spalte
        // mit verschiedener Semantik (einer mit ZAS-Marker, einer ohne) sind
        // genau die Sorte Doppelung, die in dieser Runde dreimal beseitigt
        // wurde. Wer das Datum aendern will, laedt den Nachweis neu hoch --
        // das ist ohnehin der richtige Vorgang, denn ein neues Ablaufdatum
        // heisst neues Dokument.
        //
        // Welche Spalten das sind, sagt der Katalog (ProofTypes), nicht eine
        // Liste hier.
        foreach (array_keys($formwerte) as $feld) {
            if (ProofTypes::istAblaufSpalte((string) $feld)) {
                unset($formwerte[$feld]);
            }
        }

        // R15-R18: Endzustandspruefung in der bindenden Reihenfolge. Was das
        // Formular nicht mitbringt, kommt vom Datensatz — im neuen Portal ist
        // das der Normalfall, weil immer nur EINE Gruppe offen steht.
        //
        // is_first_aider und is_main_employer muessen als ?bool ankommen,
        // damit die Kaskade ihre dreiwertige Abbildung anwenden kann; ein
        // (string) false waere '' und damit "unbeantwortet" (E11/R18).
        //
        // $reichweite geht MIT (C1, Fixrunde 1 zu Aufgabe 6): ein Waechter
        // blockt nur noch, wenn eines SEINER Felder zur gerade gespeicherten
        // Gruppe gehoert -- sonst friert eine fehlende Angabe (z.B.
        // Staatsangehoerigkeit) das GANZE Profil ein, auch Gruppen, die damit
        // nichts zu tun haben (Bankdaten, Hemdgroesse). Bei einer
        // Vollspeicherung ist $reichweite === $erlaubt (alle Felder), also
        // sind zwangslaeufig immer alle drei betroffen -- siehe
        // PortalProfileGuards-Docblock.
        $fehler = PortalProfileGuards::fehler($formwerte, [
            'is_first_aider'                  => $employee->is_first_aider,
            'first_aider_valid_until'         => $employee->first_aider_valid_until?->format('Y-m-d'),
            'first_aider_certificate_file_id' => $employee->first_aider_certificate_file_id,
            'nationality'                     => $employee->nationality,
            'is_main_employer'                => $employee->is_main_employer,
            'other_employer'                  => $employee->other_employer,
        ], $reichweite);
        if ($fehler !== null) {
            return ['ok' => false, 'fehler' => $fehler, 'meldung' => null];
        }

        $updates = [];
        foreach ($formwerte as $feld => $wert) {
            if (!array_key_exists($feld, $erlaubt)) {
                continue;                       // R19: manipulierter POST
            }
            $typ = $erlaubt[$feld]['type'] ?? 'text';
            if (ProofTypes::istAblaufSpalte($feld)) {
                continue;                       // F4: gehoert dem Nachweis, siehe oben
            }
            if ($typ === 'file') {
                // R20/E8: Dateien setzt ausschliesslich der Upload-Weg. Sonst
                // koennte ein manipulierter POST fremde File-Ids setzen oder
                // einen gerade geprueften Nachweis im selben Zug leeren.
                continue;
            }
            $wert = is_string($wert) ? trim($wert) : $wert;

            $updates[$feld] = $typ === 'bool'
                ? PortalBoolValue::parse($wert)                       // R22, E13
                : (($wert === '' || $wert === null) ? null : $wert);  // R22
        }

        // R21: "ja" leert den anderen Arbeitgeber — auch wenn im Formular noch
        // etwas stand oder ein alter Wert am Datensatz hing. Die Spalte ist
        // AUSSCHLIESSLICH die Antwort auf "wenn nicht wir, wer dann"; truege
        // sie zwei Bedeutungen, stuende Mueller als Hauptarbeitgeber.
        //
        // Nur soweit die offene Gruppe reicht: ein Speichern der Schuhgroesse
        // darf keine Angabe aus einem anderen Blatt veraendern.
        $flagNachher = array_key_exists('is_main_employer', $updates)
            ? $updates['is_main_employer']
            : $employee->is_main_employer;
        if ($flagNachher === true && array_key_exists('other_employer', $reichweite)) {
            $updates['other_employer'] = null;
        }

        if ($updates === []) {
            return ['ok' => true, 'fehler' => null, 'meldung' => 'Keine Änderungen.'];   // R23
        }

        $employee->update($updates);

        // Eloquent schreibt gar nicht erst, wenn nichts dirty ist — dann gibt
        // es auch keine Aenderung zu melden (und updated_at bleibt stehen).
        return $employee->wasChanged()
            ? ['ok' => true, 'fehler' => null, 'meldung' => 'Gespeichert.']
            : ['ok' => true, 'fehler' => null, 'meldung' => 'Keine Änderungen.'];
    }
}
