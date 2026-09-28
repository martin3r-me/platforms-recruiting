<?php

namespace Platform\Recruiting\Support;

/**
 * Wer teilt sich eine Handynummer — und ist das in Ordnung?
 *
 * Das Mitarbeiterportal bekommt ein Konto: Handynummer = Benutzername. Damit
 * wird die Nummer zur Identitaet. Vor der ersten Welle muss HR wissen, wo
 * eine Nummer an mehreren Datensaetzen haengt, denn genau dort wuerde ein
 * automatischer Einladungsversand zwei Menschen ein Konto anbieten (oder
 * einem Menschen zwei) (Canvas 68, Eintrag 1742).
 *
 * Regel — hart, kein Ermessen:
 *   gleicher Personen-Marker (`person_key`), ODER
 *   gleiche Nummer PLUS gleiches Geburtsdatum
 * gilt automatisch als DIESELBE Person (der normale RG/MA-Fall). Alles
 * andere ist ein Zweifelsfall fuer HR — auch "gleiche Nummer, kein
 * Geburtsdatum auf beiden Seiten". Ein leeres `birth_date` oder ein leerer
 * `person_key` bestaetigt NIE eine Gleichheit — zwei Datensaetze ohne
 * Geburtsdatum an derselben Nummer sind kein Beleg fuer denselben Menschen.
 * Gleiches Prinzip wie in PersonProofScope ("Leere Suffixe duerfen sich
 * NICHT gegenseitig bestaetigen" / "Im Zweifel zeigen wir WENIGER, nicht
 * mehr") — hier gilt es umgekehrt: im Zweifel gilt NICHT "dieselbe Person".
 *
 * "Dieselbe Nummer" heisst nicht der rohe Spaltenwert, sondern
 * PhoneE164::suffix() — die letzten neun Ziffern, damit Schreibweisen wie
 * "+49 152 ...", "0152 ..." und "0049152 ..." zusammenfallen. Suffixe, die
 * leer zurueckkommen (weniger als neun Ziffern), sind keine brauchbare
 * Nummer und fallen aus der Betrachtung komplett raus — der gehoert der
 * bereits vorhandene Fall "ohne_telefon_akte".
 *
 * Eine Gruppe mit mehr als zwei Datensaetzen, in der die Regel nur fuer
 * einen Teil greift, geht KOMPLETT nach andere_person: HR muss die ganze
 * Gruppe sehen, um zu entscheiden, nicht nur den ungeklaerten Rest — sonst
 * fehlt der Kontext, wer sich die Nummer eigentlich teilt.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class SharedPhonePlanner
{
    /**
     * @param  list<array{id:int, phone:?string, birth_date:?string, person_key:?string}>  $employees
     * @return array{gleiche_person: list<int>, andere_person: list<int>}
     */
    public static function plan(array $employees): array
    {
        $gruppen = [];
        foreach ($employees as $mitarbeiter) {
            $suffix = PhoneE164::suffix($mitarbeiter['phone'] ?? null);
            if ($suffix === '') {
                continue;
            }
            $gruppen[$suffix][] = $mitarbeiter;
        }

        $gleichePerson = [];
        $andrePerson = [];

        foreach ($gruppen as $gruppe) {
            if (count($gruppe) < 2) {
                continue;
            }

            if (self::gehoertZusammen($gruppe)) {
                foreach ($gruppe as $mitarbeiter) {
                    $gleichePerson[] = (int) $mitarbeiter['id'];
                }
                continue;
            }

            foreach ($gruppe as $mitarbeiter) {
                $andrePerson[] = (int) $mitarbeiter['id'];
            }
        }

        sort($gleichePerson);
        sort($andrePerson);

        return [
            'gleiche_person' => array_values(array_unique($gleichePerson)),
            'andere_person' => array_values(array_unique($andrePerson)),
        ];
    }

    /**
     * Alle Datensaetze einer Nummerngruppe tragen denselben nicht-leeren
     * `person_key` ODER alle tragen dasselbe nicht-leere `birth_date`
     * (nur der Tagesanteil, 'YYYY-MM-DD' und 'YYYY-MM-DD HH:MM:SS' zaehlen
     * gleich) — dann ist die Gruppe die eine erwartete Person (RG+MA).
     *
     * @param  list<array{id:int, phone:?string, birth_date:?string, person_key:?string}>  $gruppe
     */
    private static function gehoertZusammen(array $gruppe): bool
    {
        $keys = [];
        $tage = [];
        foreach ($gruppe as $mitarbeiter) {
            $keys[] = trim((string) ($mitarbeiter['person_key'] ?? ''));
            $tage[] = substr(trim((string) ($mitarbeiter['birth_date'] ?? '')), 0, 10);
        }

        if (!in_array('', $keys, true) && count(array_unique($keys)) === 1) {
            return true;
        }

        if (!in_array('', $tage, true) && count(array_unique($tage)) === 1) {
            return true;
        }

        return false;
    }
}
