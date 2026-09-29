<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PasswortRegeln;

/**
 * Der Benutzername ist die Handynummer, oeffentlich sichtbar im Gruppenchat
 * und auf dem Dienstplan. Deshalb traegt allein das Passwort die Sicherheit
 * — Laenge statt erzwungener Zeichenklassen (Spec §2.3).
 */
final class PasswortRegelnTest extends TestCase
{
    public function test_ein_zu_kurzes_passwort_wird_abgelehnt(): void
    {
        $meldung = PasswortRegeln::pruefe(str_repeat('a', PasswortRegeln::MINDESTLAENGE - 1));
        $this->assertNotNull($meldung);
    }

    public function test_genau_die_mindestlaenge_reicht(): void
    {
        $this->assertNull(PasswortRegeln::pruefe(str_repeat('a', PasswortRegeln::MINDESTLAENGE)));
    }

    public function test_ein_leeres_passwort_wird_abgelehnt(): void
    {
        $this->assertNotNull(PasswortRegeln::pruefe(''));
    }

    /** Kein Zwang zu Sonderzeichen/Ziffern/Grossbuchstaben — Laenge reicht. */
    public function test_ein_langes_passwort_ohne_sonderzeichen_ist_gueltig(): void
    {
        $this->assertNull(PasswortRegeln::pruefe('nurkleinbuchstaben'));
    }

    /**
     * F3: der Fall, der mb_strlen wirklich von strlen unterscheidet. 'äöüäöüä'
     * hat 7 Zeichen, aber 14 Byte in UTF-8 — unter mb_strlen zu kurz
     * (abgelehnt), unter strlen waeren 14 Byte >= 10 und faelschlich gueltig.
     * Ein frueherer Test hier nahm 10 Zeichen/19 Byte und blieb auch unter
     * strlen gueltig — er bewies nichts (Pruefbericht F3).
     */
    public function test_umlaute_zaehlen_als_ein_zeichen_nicht_als_mehrere_byte(): void
    {
        $meldung = PasswortRegeln::pruefe('äöüäöüä');
        $this->assertNotNull($meldung);
    }

    public function test_die_meldung_nennt_die_mindestlaenge(): void
    {
        $meldung = PasswortRegeln::pruefe('kurz');
        $this->assertStringContainsString((string) PasswortRegeln::MINDESTLAENGE, (string) $meldung);
    }

    /** F4: die 10 ist die spec-tragende Zahl (Spec §2.3) — woertlich festnageln. */
    public function test_die_mindestlaenge_ist_zehn(): void
    {
        $this->assertSame(10, PasswortRegeln::MINDESTLAENGE);
    }

    /** F4: neun Zeichen sind ausdruecklich zu wenig. */
    public function test_neun_zeichen_sind_zu_wenig(): void
    {
        $this->assertNotNull(PasswortRegeln::pruefe(str_repeat('a', 9)));
    }

    /** F2: ein Nullbyte liess spaeter password_hash() mit ValueError abstuerzen. */
    public function test_ein_passwort_mit_nullbyte_wird_abgelehnt(): void
    {
        $this->assertNotNull(PasswortRegeln::pruefe(str_repeat("\0", 10)));
    }

    /** F2: auch andere Steuerzeichen (z.B. Tab) werden abgewiesen. */
    public function test_ein_passwort_mit_tabulator_wird_abgelehnt(): void
    {
        $this->assertNotNull(PasswortRegeln::pruefe("geheim\tpasswort"));
    }

    /** F2: genau die Hoechstlaenge ist noch gueltig. */
    public function test_genau_die_hoechstlaenge_ist_noch_gueltig(): void
    {
        $this->assertNull(PasswortRegeln::pruefe(str_repeat('a', PasswortRegeln::HOECHSTLAENGE)));
    }

    /** F2: ein Zeichen mehr als die Hoechstlaenge wird abgelehnt. */
    public function test_ein_zeichen_ueber_der_hoechstlaenge_wird_abgelehnt(): void
    {
        $this->assertNotNull(PasswortRegeln::pruefe(str_repeat('a', PasswortRegeln::HOECHSTLAENGE + 1)));
    }

    /** F2: es wird nicht getrimmt — Leerzeichen zaehlen als normale Zeichen. */
    public function test_es_wird_nicht_getrimmt(): void
    {
        $this->assertNull(PasswortRegeln::pruefe(str_repeat(' ', 10)));
    }
}
