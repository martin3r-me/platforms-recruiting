<?php

namespace Platform\Recruiting\Support;

/**
 * Einladung zum Konto: EIN Geheimnis in zwei Darreichungsformen — als
 * Pfadstueck im Einladungslink und als kurzer Code, den man am Rechner
 * manuell eintippt (Canvas 68, Eintrag 1740: "Der Link oeffnet die
 * Registrierungsseite mit vorbelegtem Token; am Rechner kann man den Code
 * auch eintippen."). Beides ist derselbe Wert aus `erzeuge()`, kein Link-Token
 * und ein davon ABGELEITETER Anzeige-Code (Ruling GD-4 — eine fruehere
 * `lesbar()`-Ableitung aus dem Klartext wurde deshalb ersatzlos entfernt: eine
 * Ableitung, die nicht in `istGueltig()` einfliesst, kann man nicht eintippen).
 *
 * Gespeichert wird NIE der Klartext, sondern nur sein SHA-256-Hash
 * (Schema: rec_persons.invite_token_hash, VARCHAR(64) — siehe Kommentar an
 * RecPerson: "genau wie schon bei password_hash"). Der Klartext existiert
 * nur den einen Moment lang, in dem er erzeugt und in Link sowie Eingabefeld
 * geschrieben wird.
 *
 * `random_int()` statt `rand()`/`mt_rand()`: Letztere sind fuer
 * Sicherheitszwecke vorhersagbar (Mersenne-Twister, aus Zeit/PID
 * rekonstruierbar). Ein Einladungs-Token, das sich erraten laesst, ist kein
 * Nachweis mehr, dass die Einladung tatsaechlich bei der eingeladenen Person
 * ankam.
 *
 * Acht Zeichen aus einem 31-Zeichen-Alphabet sind rund 850 Milliarden
 * Moeglichkeiten — fuer sich genommen WENIG fuer ein Geheimnis, das sieben
 * Tage gilt. Es traegt hier trotzdem, aber NUR wegen des zweiten Nachweises:
 * ohne das passende Geburtsdatum nuetzt ein erratener Token nichts
 * (Zwei-Nachweis-Regel, Spec §2.4). Daraus folgt zwingend: die
 * Registrierungsseite, die diesen Token entgegennimmt, braucht selbst noch
 * eine Drossel auf Fehlversuche (Aufgabe 6) — ohne sie waere die
 * Zwei-Nachweis-Regel die einzige Bremse gegen automatisiertes Durchprobieren.
 *
 * Alphabet ohne 0/O und 1/I/L — bewusst STRENGER als
 * `RefCodeParser::ALPHABET` (das laesst dort nur I, O, 0, 1 weg, L bleibt
 * drin). Das ist keine Inkonsistenz, die es anzugleichen gilt: dieser Code
 * wird am Telefon vorgelesen, und kleines l/1 sind in den meisten Schriften
 * kaum zu unterscheiden — ein anderer Zweck als bei RefCodeParser, deshalb
 * ein eigenes, engeres Alphabet.
 *
 * Ruling GD-5: gehasht wird mit `hash_hmac('sha256', $klartext, $pepper)`,
 * nicht mit einem blossen `hash('sha256', ...)`. Acht Zeichen aus 31 sind
 * "nur" rund 850 Milliarden Moeglichkeiten (s.o.) — ungesalzenes SHA-256
 * laeuft auf handelsueblicher GPU-Hardware im zweistelligen
 * Milliarden-Bereich pro Sekunde, der gesamte Schluesselraum waere in
 * Sekunden durch, und zwar fuer ALLE Zeilen eines Dumps gleichzeitig. Die
 * Zwei-Nachweis-Regel (s.o.) rettet hier nichts, weil der zweite Nachweis
 * (Geburtsdatum) in derselben Datenbank steht wie der Hash.
 *
 * Der Pfeffer wird als Parameter hereingereicht, NICHT gelesen — die Klasse
 * bleibt rein. Er lebt spaeter in der `.env` des Servers, also gerade
 * NICHT in der Datenbank: ein Dump allein reicht dann nicht mehr fuer die
 * Ruecktransformation. Ein leerer Pfeffer ist ein Konfigurationsfehler, kein
 * Normalfall, und wirft deshalb eine `InvalidArgumentException` — still
 * ungepfeffert weiterzurechnen saehe sicher aus und waere es nicht.
 *
 * Der Pfeffer betrifft AUSSCHLIESSLICH dieses kurzlebige Geheimnis (sieben
 * Tage) und den Einmalcode (zehn Minuten) — NICHT das Passwort. Das Passwort
 * bekommt spaeter ganz normal `Hash::make()`. Grund: geht der Pfeffer
 * verloren oder wird er gewechselt, sterben alle offenen Einladungen und
 * Codes — verschmerzbar, weil kurzlebig. Waere das Passwort mitgepfeffert,
 * wuerde derselbe Verlust jeden Mitarbeiter DAUERHAFT aussperren. Diese
 * Asymmetrie ist Absicht.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class EinladungsToken
{
    /** Spec: eine Einladung ist eine Woche lang gueltig. */
    public const GUELTIG_TAGE = 7;

    /** Ohne 0/O und 1/I/L — Verwechsler beim Vorlesen/Abtippen. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LAENGE = 8;

    /**
     * @return array{klartext: string, hash: string}
     */
    public static function erzeuge(string $pepper): array
    {
        $laenge = strlen(self::ALPHABET);

        $klartext = '';
        for ($i = 0; $i < self::LAENGE; $i++) {
            $klartext .= self::ALPHABET[random_int(0, $laenge - 1)];
        }

        return [
            'klartext' => $klartext,
            'hash' => self::hash($klartext, $pepper),
        ];
    }

    /**
     * Abgelaufen, schon benutzt oder falscher Klartext ergeben alle dasselbe
     * `false` — wer nur die Antwortzeit oder den Rueckgabewert sieht, soll
     * nicht erfahren, welcher Grund zutraf. Fehlender Ablauf (`$ablauf ===
     * null`) zaehlt ebenfalls als ungueltig: bei einem Geheimnis ist die
     * sichere Richtung die, die im Zweifel ablehnt, nicht die, die im
     * Zweifel durchlaesst. Der eigentliche Vergleich laeuft ueber
     * `hash_equals()`, NIE ueber `===`: `===` bricht beim ersten
     * abweichenden Zeichen ab und verraet ueber die Antwortzeit, wie viele
     * Stellen des Hash schon stimmen.
     */
    public static function istGueltig(?string $hash, ?string $ablauf, ?string $benutztAm, string $klartext, string $jetzt, string $pepper): bool
    {
        if ($hash === null || $ablauf === null || $benutztAm !== null) {
            return false;
        }

        if (new \DateTimeImmutable($jetzt) >= new \DateTimeImmutable($ablauf)) {
            return false;
        }

        return hash_equals($hash, self::hash($klartext, $pepper));
    }

    private static function hash(string $klartext, string $pepper): string
    {
        if ($pepper === '') {
            throw new \InvalidArgumentException('Pepper darf nicht leer sein.');
        }

        return hash_hmac('sha256', $klartext, $pepper);
    }
}
