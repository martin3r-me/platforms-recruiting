<?php

namespace Platform\Recruiting\Support;

/**
 * Der sechsstellige Einmalcode fuers Konto — Anmeldung, Passwort-Reset,
 * Nummernwechsel (RecPerson.code_zweck haelt fest, wofuer; diese Klasse
 * kennt den Zweck nicht, nur die Gueltigkeit).
 *
 * `random_int()` statt `rand()`/`mt_rand()`: Letztere sind fuer
 * Sicherheitszwecke vorhersagbar. Bei nur sechs Ziffern (eine Million
 * Kombinationen) waere ein erratbarer Generator kein Nachweis mehr, sondern
 * nur noch ein Zaehler.
 *
 * Genau diese kleine Kombinationszahl ist auch der Grund fuer
 * MAX_VERSUCHE: eine Million Werte sind am selben Nachmittag automatisiert
 * durchprobiert, wenn niemand mitzaehlt. Der Versuchszaehler macht daraus
 * hoechstens MAX_VERSUCHE Rateversuche je ausgestelltem Code.
 *
 * Ruling GD-5: gehasht wird mit `hash_hmac('sha256', $klartext, $pepper)`,
 * nicht mit einem blossen `hash('sha256', ...)`. Ein ungesalzenes SHA-256
 * ueber sechs Ziffern hat nur eine Million Moeglichkeiten — aus einem
 * SQL-Dump in Millisekunden rueckgerechnet, fuer ALLE Zeilen in einem
 * Durchlauf. Die Zwei-Nachweis-Regel rettet hier nichts, weil der zweite
 * Nachweis (Geburtsdatum) in derselben Datenbank steht wie der Hash.
 *
 * Der Pfeffer wird als Parameter hereingereicht, NICHT gelesen — die Klasse
 * bleibt rein. Er lebt spaeter in der `.env` des Servers, also gerade
 * NICHT in der Datenbank: ein Dump allein reicht dann nicht mehr fuer die
 * Ruecktransformation. Ein leerer Pfeffer ist ein Konfigurationsfehler, kein
 * Normalfall, und wirft deshalb eine `InvalidArgumentException` — still
 * ungepfeffert weiterzurechnen saehe sicher aus und waere es nicht.
 *
 * Der Pfeffer betrifft AUSSCHLIESSLICH dieses kurzlebige Geheimnis (zehn
 * Minuten) und den Einladungs-Token (sieben Tage) — NICHT das Passwort. Das
 * Passwort bekommt spaeter ganz normal `Hash::make()`. Grund: geht der
 * Pfeffer verloren oder wird er gewechselt, sterben alle offenen Codes und
 * Einladungen — verschmerzbar, weil kurzlebig. Waere das Passwort mitgepfeffert,
 * wuerde derselbe Verlust jeden Mitarbeiter DAUERHAFT aussperren. Diese
 * Asymmetrie ist Absicht.
 *
 * Der VERGLEICH des Geheimnisses laeuft ueber `hash_equals()`, NIE ueber
 * `===`: ein Vergleich, der beim ersten abweichenden Zeichen abbricht,
 * verraet ueber die Antwortzeit, wie viele Ziffern schon stimmen — bei
 * sechs Ziffern der Unterschied zwischen einer Million Versuchen und
 * sechzig. (Ueber den RUECKGABEWERT verraet die Funktion ohnehin nie den
 * Grund — dass die fruehen Ablehnungen die Hash-Berechnung ueberspringen,
 * kostet nur Mikrosekunden und ist ueber Netz nicht ausnutzbar; das
 * Zeitgleichheits-Versprechen gilt fuer den Geheimnisvergleich selbst.)
 *
 * Abgelaufen, schon zu oft falsch versucht, ein unlesbarer Zeitstempel oder
 * schlicht falsch: alle vier ergeben `false`, ohne zu verraten, welcher
 * Grund zutraf. Ein unlesbarer `$jetzt`/`$ablauf` ist ein Datenfehler, kein
 * Nachweis — dieselbe sichere Richtung wie bei fehlendem Ablauf (im
 * Zweifel ungueltig statt einer 500er-Antwort).
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class Einmalcode
{
    /** Spec: ein Einmalcode gilt zehn Minuten. */
    public const GUELTIG_MINUTEN = 10;

    /** Ab dem fuenften Fehlversuch gilt auch der richtige Code nicht mehr. */
    public const MAX_VERSUCHE = 5;

    /**
     * @return array{klartext: string, hash: string}
     */
    public static function erzeuge(string $pepper): array
    {
        // 0 bis 999999, mit fuehrenden Nullen aufgefuellt: die volle
        // Spannweite von sechs Ziffern wird genutzt, nicht nur 100000-999999.
        $klartext = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [
            'klartext' => $klartext,
            'hash' => self::hash($klartext, $pepper),
        ];
    }

    public static function istGueltig(?string $hash, ?string $ablauf, int $versuche, string $klartext, string $jetzt, string $pepper): bool
    {
        if ($hash === null || $ablauf === null) {
            return false;
        }

        if ($versuche >= self::MAX_VERSUCHE) {
            return false;
        }

        try {
            $abgelaufen = new \DateTimeImmutable($jetzt) >= new \DateTimeImmutable($ablauf);
        } catch (\Throwable) {
            // Unlesbarer Zeitstempel = Datenfehler, kein Nachweis. Im
            // Zweifel ungueltig statt einer 500er-Antwort (F11).
            return false;
        }

        if ($abgelaufen) {
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
