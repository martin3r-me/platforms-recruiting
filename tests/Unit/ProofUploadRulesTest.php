<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ProofUploadRules;

/**
 * Reine Regeln fuers Hochladen eines Nachweises: welches Ablaufdatum ist
 * zulaessig. Die Datei- und Groessenregeln (MIME_TYPES, MAX_KB) haengt die
 * spaetere Livewire-Strecke davor — hier geht es nur um pruefeDatum().
 */
final class ProofUploadRulesTest extends TestCase
{
    public function test_art_mit_ablauf_verlangt_ein_datum(): void
    {
        $this->assertSame(
            'Bitte trag ein, bis wann der Nachweis gültig ist.',
            ProofUploadRules::pruefeDatum('ausweis', null, '2026-09-24'),
        );
    }

    public function test_datum_in_der_vergangenheit_wird_abgewiesen(): void
    {
        // Sonst legt jemand einen abgelaufenen Ausweis ab und die Aufgabe
        // verschwindet, obwohl sich nichts gebessert hat.
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '2026-09-23', '2026-09-24'));
    }

    public function test_heute_ist_noch_gueltig(): void
    {
        $this->assertNull(ProofUploadRules::pruefeDatum('ausweis', '2026-09-24', '2026-09-24'));
    }

    public function test_mehr_als_zehn_jahre_wird_abgewiesen(): void
    {
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '2040-01-01', '2026-09-24'));
    }

    public function test_art_ohne_ablauf_nimmt_kein_datum(): void
    {
        $this->assertNotNull(ProofUploadRules::pruefeDatum('selfie', '2030-01-01', '2026-09-24'));
        $this->assertNull(ProofUploadRules::pruefeDatum('selfie', null, '2026-09-24'));
    }

    public function test_unbekannte_art_wird_abgewiesen(): void
    {
        $this->assertNotNull(ProofUploadRules::pruefeDatum('gibtesnicht', null, '2026-09-24'));
    }

    public function test_unsinniges_datum_wird_abgewiesen(): void
    {
        // '0000-00-00' hat in Schritt 1 schon einmal eine Falle gestellt.
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '0000-00-00', '2026-09-24'));
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', 'morgen', '2026-09-24'));
    }
}
