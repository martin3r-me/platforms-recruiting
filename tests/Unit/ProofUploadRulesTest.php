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

    public function test_genau_zehn_jahre_ist_noch_erlaubt(): void
    {
        // Die Grenze selbst gehoert zum erlaubten Bereich.
        $this->assertNull(ProofUploadRules::pruefeDatum('ausweis', '2036-09-24', '2026-09-24'));
    }

    public function test_ein_tag_ueber_zehn_jahren_wird_abgewiesen(): void
    {
        // Ein Tag darueber muss kippen — sonst haelt kein Test die Grenze fest
        // und sie koennte sich unbemerkt verschieben.
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', '2036-09-25', '2026-09-24'));
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

    /**
     * Kundenfeedback 24.09.2026: eine Niederlassungserlaubnis oder manche
     * Arbeitserlaubnis laeuft nicht ab. Bei "unbefristet" gehen Aufenthaltstitel
     * und Arbeitsgenehmigung ohne Datum durch — sonst muesste der Betroffene ein
     * erfundenes Datum eintragen, das spaeter eine falsche Erinnerung ausloest.
     */
    public function test_unbefristet_laesst_leeres_datum_bei_aufenthaltstitel_und_arbeitsgenehmigung_durch(): void
    {
        $this->assertNull(ProofUploadRules::pruefeDatum('aufenthaltstitel', null, '2026-09-24', true));
        $this->assertNull(ProofUploadRules::pruefeDatum('arbeitsgenehmigung', '', '2026-09-24', true));
    }

    public function test_unbefristet_geht_bei_allen_anderen_arten_nicht(): void
    {
        // Ein Pass, ein Visum, eine Fiktionsbescheinigung, eine Schulbescheinigung
        // und ein Ersthelferschein laufen immer ab — auch wenn ein manipuliertes
        // $wire.set das Haekchen setzt, darf hier kein leeres Datum durchgehen.
        $this->assertNotNull(ProofUploadRules::pruefeDatum('ausweis', null, '2026-09-24', true));
        $this->assertNotNull(ProofUploadRules::pruefeDatum('nationalpass', null, '2026-09-24', true));
    }

    public function test_ohne_unbefristet_gilt_die_alte_regel_weiter(): void
    {
        $this->assertSame(
            'Bitte trag ein, bis wann der Nachweis gültig ist.',
            ProofUploadRules::pruefeDatum('aufenthaltstitel', null, '2026-09-24'),
        );
    }
}
