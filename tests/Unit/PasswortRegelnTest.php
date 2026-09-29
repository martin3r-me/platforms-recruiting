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

    /** Umlaute zaehlen als ein Zeichen, nicht als mehrere Bytes. */
    public function test_umlaute_werden_nicht_als_mehrere_zeichen_gezaehlt(): void
    {
        // 10 Zeichen, davon 3 Umlaute — als UTF-8-Bytes waeren es mehr als 10.
        $meldung = PasswortRegeln::pruefe('äöüäöüäöüx');
        $this->assertNull($meldung);
    }

    public function test_die_meldung_nennt_die_mindestlaenge(): void
    {
        $meldung = PasswortRegeln::pruefe('kurz');
        $this->assertStringContainsString((string) PasswortRegeln::MINDESTLAENGE, (string) $meldung);
    }
}
