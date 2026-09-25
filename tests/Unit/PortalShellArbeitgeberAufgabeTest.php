<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Public\PortalShell;

/**
 * Die synthetische oberste Aufgabe im Start-Bereich: die
 * Arbeitgeber-Pflichtfrage (Markus 24.09.2026), solange sie unbeantwortet
 * ist. Kein Nachweis -- deshalb kein ProofChecklist-Eintrag, sondern eine
 * eigene Zeile in derselben Form (siehe PortalShellDecorationTest fuer die
 * Nachweis-Variante), damit die Blade-Seite beide gleich rendern kann.
 */
class PortalShellArbeitgeberAufgabeTest extends TestCase
{
    public function test_zeile_hat_dieselbe_form_wie_eine_dekorierte_nachweis_zeile(): void
    {
        $zeile = PortalShell::arbeitgeberAufgabe(false);

        $this->assertSame(['code', 'label', 'punkt', 'text', 'offen'], array_keys($zeile));
        $this->assertTrue($zeile['offen']);
        $this->assertSame('crit', $zeile['punkt']);
    }

    public function test_code_ist_kein_proof_types_code(): void
    {
        // Sonst koennte ein Klick versehentlich das Upload-Formular fuer
        // eine echte Nachweisart oeffnen.
        $this->assertFalse(\Platform\Recruiting\Support\ProofTypes::exists(PortalShell::arbeitgeberAufgabe(false)['code']));
    }

    public function test_text_nennt_die_steuerklasse(): void
    {
        // Der Grund, warum diese Aufgabe wichtiger ist als jeder Nachweis,
        // muss im Text stehen -- sonst wirkt sie wie eine von vielen.
        $this->assertStringContainsString('Steuerklasse', PortalShell::arbeitgeberAufgabe(false)['text']);
        $this->assertStringContainsString('Steuerklasse', PortalShell::arbeitgeberAufgabe(true)['text']);
    }

    public function test_anrede_folgt_duzen(): void
    {
        $siezen = PortalShell::arbeitgeberAufgabe(false);
        $duzen  = PortalShell::arbeitgeberAufgabe(true);

        $this->assertStringContainsString('Sie', $siezen['text']);
        $this->assertStringNotContainsString(' Sie ', $duzen['text']);
    }
}
