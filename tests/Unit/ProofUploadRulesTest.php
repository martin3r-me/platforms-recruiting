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
            ProofUploadRules::pruefeDatum('ausweis', null, '2026-09-24', true),
        );
    }

    public function test_datum_in_der_vergangenheit_wird_abgewiesen(): void
    {
        // Sonst legt jemand einen abgelaufenen Ausweis ab und die Aufgabe
        // verschwindet, obwohl sich nichts gebessert hat.
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '2026-09-23', '2026-09-24', true));
    }

    public function test_heute_ist_noch_gueltig(): void
    {
        $this->assertNull(ProofUploadRules::pruefeDatum('ausweis', '2026-09-24', '2026-09-24', true));
    }

    public function test_mehr_als_zehn_jahre_wird_abgewiesen(): void
    {
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '2040-01-01', '2026-09-24', true));
    }

    public function test_genau_zehn_jahre_ist_noch_erlaubt(): void
    {
        // Die Grenze selbst gehoert zum erlaubten Bereich.
        $this->assertNull(ProofUploadRules::pruefeDatum('ausweis', '2036-09-24', '2026-09-24', true));
    }

    public function test_ein_tag_ueber_zehn_jahren_wird_abgewiesen(): void
    {
        // Ein Tag darueber muss kippen — sonst haelt kein Test die Grenze fest
        // und sie koennte sich unbemerkt verschieben.
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '2036-09-25', '2026-09-24', true));
    }

    public function test_art_ohne_ablauf_nimmt_kein_datum(): void
    {
        $this->assertNotNull(ProofUploadRules::pruefeDatum('selfie', '2030-01-01', '2026-09-24', true));
        $this->assertNull(ProofUploadRules::pruefeDatum('selfie', null, '2026-09-24', true));
    }

    public function test_unbekannte_art_wird_abgewiesen(): void
    {
        $this->assertNotNull(ProofUploadRules::pruefeDatum('gibtesnicht', null, '2026-09-24', true));
    }

    public function test_unsinniges_datum_wird_abgewiesen(): void
    {
        // '0000-00-00' hat in Schritt 1 schon einmal eine Falle gestellt.
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '0000-00-00', '2026-09-24', true));
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', 'morgen', '2026-09-24', true));
    }

    // -----------------------------------------------------------------
    // F6 -- die Anrede
    // -----------------------------------------------------------------

    public function test_die_datums_aufforderung_folgt_der_anrede(): void
    {
        // Die einzige Meldung dieser Klasse, die den Menschen anspricht.
        // Sie stand nur in der Du-Form, obwohl die Anrede im Portal an der
        // Team-Einstellung haengt.
        $duzen  = ProofUploadRules::pruefeDatum('ausweis', '', '2026-09-24', true);
        $siezen = ProofUploadRules::pruefeDatum('ausweis', '', '2026-09-24', false);

        $this->assertSame('Bitte trag ein, bis wann der Nachweis gültig ist.', $duzen);
        $this->assertSame('Bitte tragen Sie ein, bis wann der Nachweis gültig ist.', $siezen);
    }

    public function test_die_uebrigen_meldungen_haben_bewusst_keine_anrede(): void
    {
        // Sie beschreiben das DATUM, nicht den Menschen -- ein Ternary waere
        // dort Ballast, der bei jeder Textaenderung doppelt gepflegt werden
        // muesste.
        foreach ([
            ['ausweis', '2026-09-23'],      // Vergangenheit
            ['ausweis', 'morgen'],          // unlesbar
            ['selfie', '2030-01-01'],       // Art ohne Ablauf
            ['gibtesnicht', null],          // unbekannte Art
        ] as [$code, $datum]) {
            $this->assertSame(
                ProofUploadRules::pruefeDatum($code, $datum, '2026-09-24', true),
                ProofUploadRules::pruefeDatum($code, $datum, '2026-09-24', false),
                "Die Meldung zu {$code} unterscheidet jetzt doch nach Anrede — dann gehoert sie geprueft.",
            );
        }
    }
}
