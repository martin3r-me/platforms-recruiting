<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\PortalSectionHints;

/**
 * Die zwei Erklaertexte, die im alten Portal fest im Blade standen
 * (employee-portal.blade.php:255-270) und zu keinem Feld gehoeren — keine
 * generische Feldruebernahme nimmt sie mit, kein Test hielt sie bisher fest.
 *
 * Der Arbeitgeber-Text ist der einzige Ort im Produkt, der den
 * Minijob-Sonderfall bei der Arbeitgeber-Frage erklaert (E15): faellt er
 * weg, entstehen falsche Steuerklassen ausgerechnet bei Schuelern und
 * Studenten.
 */
final class PortalSectionHintsTest extends TestCase
{
    public function test_arbeitgeber_text_nennt_den_minijob(): void
    {
        // E15: "Der Job, bei dem du am meisten verdienst" war sachlich falsch.
        // Ein 556-Euro-Minijob woanders zaehlt NICHT mit — bei Schuelern und
        // Studenten, also der Mehrheit der Zielgruppe, ist das der Normalfall.
        foreach ([true, false] as $duzen) {
            $text = PortalSectionHints::fuer('Arbeitgeber', $duzen);

            $this->assertNotNull($text);
            $this->assertStringContainsString('Minijob', $text);
            $this->assertStringNotContainsString('am meisten verdienst', $text);
            $this->assertStringNotContainsString('am meisten verdienen', $text);
        }
    }

    public function test_arbeitgeber_text_laedt_zum_nachfragen_ein(): void
    {
        $this->assertStringContainsString('Frag uns', PortalSectionHints::fuer('Arbeitgeber', true));
        $this->assertStringContainsString('Fragen Sie uns', PortalSectionHints::fuer('Arbeitgeber', false));
    }

    public function test_arbeitsschutz_text_nennt_beide_pflichten(): void
    {
        foreach ([true, false] as $duzen) {
            $text = PortalSectionHints::fuer('Arbeitsschutz', $duzen);

            $this->assertNotNull($text);
            $this->assertStringContainsString('Ersthelfer', $text);
            // Datum UND Schein — R15 verlangt beides.
            $this->assertMatchesRegularExpression('/g(ü|ue)ltig/i', $text);
            $this->assertStringContainsString('Schein', $text);
        }
    }

    public function test_alle_anderen_gruppen_haben_keinen_text(): void
    {
        $this->assertNull(PortalSectionHints::fuer('Bankdaten', true));
        $this->assertNull(PortalSectionHints::fuer('Kontakt', false));
    }

    public function test_du_und_sie_sind_wirklich_verschieden(): void
    {
        $this->assertNotSame(
            PortalSectionHints::fuer('Arbeitgeber', true),
            PortalSectionHints::fuer('Arbeitgeber', false),
        );
    }
}
