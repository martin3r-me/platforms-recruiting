<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TaxAndSvNumber;

/**
 * Steuer-ID und Sozialversicherungsnummer ohne Leerzeichen (Clara, 28.08.2026):
 * "sonst landet spaeter nicht die vollstaendige Nummer in Agenda".
 *
 * Nur Leerraum faellt weg — Buchstaben, Ziffern und alles andere bleiben
 * unangetastet. Ein zu strenger Filter wuerde stillschweigend Werte
 * veraendern, und falsche Nummern fallen niemandem auf.
 */
class TaxAndSvNumberTest extends TestCase
{
    public function test_spaces_are_removed(): void
    {
        $this->assertSame('12345678901', TaxAndSvNumber::normalize('12 345 678 901'));
    }

    public function test_sv_number_keeps_its_letter(): void
    {
        // Format der SV-Nummer: Bereichsnummer, Geburtsdatum, Anfangsbuchstabe, Seriennummer
        $this->assertSame('12190367K123', TaxAndSvNumber::normalize('12 190367 K 123'));
    }

    public function test_tabs_newlines_and_non_breaking_spaces_are_removed(): void
    {
        // Geschuetztes Leerzeichen kommt beim Kopieren aus Word und PDFs mit
        // und ist im Feld unsichtbar.
        $this->assertSame('12345678901', TaxAndSvNumber::normalize("12\t345\u{00A0}678\n901"));
    }

    public function test_empty_and_null_stay_empty(): void
    {
        $this->assertNull(TaxAndSvNumber::normalize(null));
        $this->assertNull(TaxAndSvNumber::normalize(''));
        $this->assertNull(TaxAndSvNumber::normalize('   '));
    }

    public function test_other_characters_are_untouched(): void
    {
        // Kein Filter auf Ziffern: was HR eintraegt, bleibt inhaltlich stehen.
        $this->assertSame('12/345-678', TaxAndSvNumber::normalize('12 / 345 - 678'));
    }

    public function test_clean_values_are_returned_unchanged(): void
    {
        $this->assertSame('12345678901', TaxAndSvNumber::normalize('12345678901'));
    }
}
