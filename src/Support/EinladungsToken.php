<?php

namespace Platform\Recruiting\Support;

/**
 * Einladung zum Konto: ein Link-Token, das HR einmalig verschickt.
 *
 * Gespeichert wird NIE der Klartext, sondern nur sein SHA-256-Hash
 * (Schema: rec_persons.invite_token_hash, VARCHAR(64) — siehe Kommentar an
 * RecPerson: "genau wie schon bei password_hash"). Der Klartext existiert
 * nur den einen Moment lang, in dem er erzeugt und in den Einladungslink
 * geschrieben wird.
 *
 * `random_bytes()` statt `rand()`/`mt_rand()`: Letztere sind fuer
 * Sicherheitszwecke vorhersagbar (Mersenne-Twister, aus Zeit/PID
 * rekonstruierbar). Ein Einladungs-Token, das sich erraten laesst, ist kein
 * Nachweis mehr, dass die Einladung tatsaechlich bei der eingeladenen Person
 * ankam.
 *
 * `lesbar()` liefert eine ZWEITE, kurze Darstellung DESSELBEN Tokens fuer den
 * Fall, dass der Link nicht ankommt oder nicht anklickbar ist (z.B. am
 * Telefon vorgelesen). Sie laesst `0/O` und `1/I/L` weg, weil genau diese
 * Zeichen beim Vorlesen und Abtippen verwechselt werden. Der eigentliche
 * Nachweis bleibt der lange Klartext aus `erzeuge()` — `lesbar()` ist eine
 * Anzeigeform davon, kein zweites, unabhaengig gueltiges Geheimnis, und
 * fliesst deshalb auch nicht in `istGueltig()` ein.
 *
 * Reine Logik (kein Framework/DB) → pure-unit-testbar.
 */
final class EinladungsToken
{
    /** Spec: eine Einladung ist eine Woche lang gueltig. */
    public const GUELTIG_TAGE = 7;

    /** Ohne 0/O und 1/I/L — Verwechsler beim Vorlesen/Abtippen. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * @return array{klartext: string, hash: string}
     */
    public static function erzeuge(): array
    {
        // 32 zufaellige Bytes (256 Bit) — bin2hex macht daraus 64 Hex-Zeichen,
        // genau die Spaltenbreite von invite_token_hash.
        $klartext = bin2hex(random_bytes(32));

        return [
            'klartext' => $klartext,
            'hash' => self::hash($klartext),
        ];
    }

    /**
     * Kurzform desselben Tokens zum Vorlesen/Abtippen, 8 Zeichen aus
     * ALPHABET. Deterministisch aus dem Klartext abgeleitet (SHA-256, dann
     * jedes Byte modulo Alphabetlaenge), damit dieselbe Einladung bei
     * mehrfacher Anzeige immer dieselbe Kurzform zeigt.
     */
    public static function lesbar(string $klartext): string
    {
        $digest = hash('sha256', $klartext, true);
        $laenge = strlen(self::ALPHABET);

        $lesbar = '';
        for ($i = 0; $i < 8; $i++) {
            $lesbar .= self::ALPHABET[ord($digest[$i]) % $laenge];
        }

        return $lesbar;
    }

    /**
     * Abgelaufen, schon benutzt oder falscher Klartext ergeben alle dasselbe
     * `false` — wer nur die Antwortzeit oder den Rueckgabewert sieht, soll
     * nicht erfahren, welcher Grund zutraf. Der eigentliche Vergleich laeuft
     * ueber `hash_equals()`, NIE ueber `===`: `===` bricht beim ersten
     * abweichenden Zeichen ab und verraet ueber die Antwortzeit, wie viele
     * Stellen des Hash schon stimmen.
     */
    public static function istGueltig(?string $hash, ?string $ablauf, ?string $benutztAm, string $klartext, string $jetzt): bool
    {
        if ($hash === null || $ablauf === null || $benutztAm !== null) {
            return false;
        }

        if (new \DateTimeImmutable($jetzt) >= new \DateTimeImmutable($ablauf)) {
            return false;
        }

        return hash_equals($hash, self::hash($klartext));
    }

    private static function hash(string $klartext): string
    {
        return hash('sha256', $klartext);
    }
}
