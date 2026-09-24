<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ProofTypes;

/**
 * Der Katalog ist die einzige Stelle, die weiss, welche Nachweisarten es
 * gibt, welche ein Ablaufdatum haben und auf welche Altspalte sie zeigen.
 * Driftet er von den 16 Dateispalten weg, faellt das hier auf — nicht erst
 * beim Doppelschreiben in eine Spalte, die es nicht gibt.
 */
final class ProofTypesTest extends TestCase
{
    public function test_jede_art_hat_label_und_gruppe(): void
    {
        foreach (ProofTypes::all() as $code) {
            $this->assertNotSame('', ProofTypes::label($code), "Art {$code} ohne Bezeichnung");
            $this->assertNotSame('', ProofTypes::group($code), "Art {$code} ohne Gruppe");
        }
    }

    public function test_deckt_alle_16_dateispalten_ab(): void
    {
        $ausKatalog = [];
        foreach (ProofTypes::all() as $code) {
            foreach (ProofTypes::legacyFileColumns($code) as $spalte) {
                $ausKatalog[] = $spalte;
            }
        }

        sort($ausKatalog);
        $erwartet = \Platform\Recruiting\Support\EmployeeFileSlots::COLUMNS;
        sort($erwartet);

        $this->assertSame($erwartet, $ausKatalog,
            'Katalog und EmployeeFileSlots muessen dieselben Spalten kennen');
    }

    public function test_nur_arten_mit_ablauf_haben_vorlaufzeit(): void
    {
        foreach (ProofTypes::all() as $code) {
            if (ProofTypes::hasExpiry($code)) {
                $this->assertGreaterThan(0, ProofTypes::leadDays($code), "Art {$code}: Ablauf ohne Vorlaufzeit");
            } else {
                $this->assertNull(ProofTypes::leadDays($code), "Art {$code}: Vorlaufzeit ohne Ablauf");
                $this->assertNull(ProofTypes::legacyExpiryColumn($code));
            }
        }
    }

    /** Aufenthaltstitel und Arbeitsgenehmigung: 60 Tage, alles andere 30 (Kundenvorgabe 22.09.). */
    public function test_vorlaufzeiten_nach_kundenvorgabe(): void
    {
        $this->assertSame(60, ProofTypes::leadDays('aufenthaltstitel'));
        $this->assertSame(60, ProofTypes::leadDays('arbeitsgenehmigung'));
        $this->assertSame(30, ProofTypes::leadDays('ausweis'));
        $this->assertSame(30, ProofTypes::leadDays('fiktionsbescheinigung'));
    }

    /** Die drei neuen Ablaufdaten haben bewusst KEINE Altspalte — sie gehen nicht nach ZAS. */
    public function test_neue_ablaufdaten_ohne_altspalte(): void
    {
        foreach (['nationalpass', 'visum', 'fiktionsbescheinigung'] as $code) {
            $this->assertTrue(ProofTypes::hasExpiry($code), "{$code} braucht ein Ablaufdatum");
            $this->assertNull(ProofTypes::legacyExpiryColumn($code),
                "{$code}: Ablaufdatum darf keine Altspalte haben, sonst landet es im ZAS-Export");
        }
    }

    public function test_pflicht_immer_ausweis(): void
    {
        $pflicht = ProofTypes::requiredFor(['is_eu_citizen' => true, 'employment_type' => null, 'is_first_aider' => false]);
        $this->assertContains('ausweis', $pflicht);
        $this->assertNotContains('aufenthaltstitel', $pflicht);
    }

    public function test_pflicht_nicht_eu(): void
    {
        $pflicht = ProofTypes::requiredFor(['is_eu_citizen' => false, 'employment_type' => null, 'is_first_aider' => false]);
        $this->assertContains('nationalpass', $pflicht);
        $this->assertContains('aufenthaltstitel', $pflicht);
        $this->assertContains('arbeitsgenehmigung', $pflicht);
    }

    /** Unbekannter EU-Status (NULL, 83 % des Bestands): NICHT nach Nicht-EU-Papieren fragen. */
    public function test_pflicht_bei_unbekanntem_eu_status_zurueckhaltend(): void
    {
        $pflicht = ProofTypes::requiredFor(['is_eu_citizen' => null, 'employment_type' => null, 'is_first_aider' => false]);
        $this->assertContains('ausweis', $pflicht);
        $this->assertNotContains('aufenthaltstitel', $pflicht);
    }

    public function test_pflicht_nach_beschaeftigungsart(): void
    {
        $schueler = ProofTypes::requiredFor(['is_eu_citizen' => true, 'employment_type' => 'schueler', 'is_first_aider' => false]);
        $this->assertContains('schulbescheinigung', $schueler);
        $this->assertNotContains('immatrikulation', $schueler);

        $student = ProofTypes::requiredFor(['is_eu_citizen' => true, 'employment_type' => 'student', 'is_first_aider' => false]);
        $this->assertContains('immatrikulation', $student);
        $this->assertNotContains('schulbescheinigung', $student);
    }

    public function test_ersthelfer_nur_wenn_gesetzt(): void
    {
        $ohne = ProofTypes::requiredFor(['is_eu_citizen' => true, 'employment_type' => null, 'is_first_aider' => false]);
        $mit  = ProofTypes::requiredFor(['is_eu_citizen' => true, 'employment_type' => null, 'is_first_aider' => true]);
        $this->assertNotContains('ersthelfer', $ohne);
        $this->assertContains('ersthelfer', $mit);
    }

    /**
     * Kundenvorgabe 22.09.2026: HR prueft ausschliesslich Lohnrelevantes.
     * Nur an Aufenthaltstitel und Arbeitsgenehmigung haengt die harte
     * Einsatzsperre — alles andere gilt mit dem Upload sofort als erledigt.
     */
    public function test_nur_aufenthaltstitel_und_arbeitsgenehmigung_verlangen_bestaetigung(): void
    {
        $this->assertTrue(ProofTypes::needsHrConfirmation('aufenthaltstitel'));
        $this->assertTrue(ProofTypes::needsHrConfirmation('arbeitsgenehmigung'));

        foreach (ProofTypes::all() as $code) {
            if (in_array($code, ['aufenthaltstitel', 'arbeitsgenehmigung'], true)) {
                continue;
            }
            $this->assertFalse(ProofTypes::needsHrConfirmation($code), "Art {$code} sollte KEINE Bestaetigung verlangen");
        }
    }
}
