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
     * Kundenvorgabe 22.09.2026: HR prueft ausschliesslich Lohnrelevantes —
     * alles andere gilt mit dem Upload sofort als erledigt. Nur Aufenthaltstitel
     * und Arbeitsgenehmigung landen auf der "zur Kenntnis"-Liste (Korrektur
     * K3, 24.09.2026: KEINE Einsatzsperre haengt daran — die gibt es fuer
     * Mitarbeiter nicht).
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

    /**
     * Die Rueckrichtung: aus der Altspalte die Nachweisart finden. Ohne sie
     * braeuchte ein Datei-Feld eine zweite Zuordnungsliste zu seinem
     * Upload-Blatt — genau das hat am 06.08.2026 (E7) zwei Feldern den
     * Hochladen-Knopf gekostet.
     */
    public function test_altspalte_findet_ihre_nachweisart(): void
    {
        $this->assertSame('ausweis', ProofTypes::codeForLegacyColumn('identity_card_front_file_id'));
        // Die Rueckseite gehoert zur selben Art — sonst braeuchte die Kachel
        // eine zweite Zuordnung, und genau die hat am 06.08. E7 verursacht.
        $this->assertSame('ausweis', ProofTypes::codeForLegacyColumn('identity_card_back_file_id'));
        $this->assertSame('selfie', ProofTypes::codeForLegacyColumn('selfie_file_id'));
        $this->assertSame('krankenkasse', ProofTypes::codeForLegacyColumn('health_insurance_card_file_id'));
        $this->assertSame('immatrikulation', ProofTypes::codeForLegacyColumn('immatrikulation_file_id'));
        $this->assertSame('schulbescheinigung', ProofTypes::codeForLegacyColumn('schulbescheinigung_file_id'));
        $this->assertSame('erstbescheinigung', ProofTypes::codeForLegacyColumn('erstbescheinigung_file_id'));
        $this->assertSame('ersthelfer', ProofTypes::codeForLegacyColumn('first_aider_certificate_file_id'));
    }

    public function test_unbekannte_spalte_liefert_nichts(): void
    {
        $this->assertNull(ProofTypes::codeForLegacyColumn('iban'));
        $this->assertNull(ProofTypes::codeForLegacyColumn(''));
    }

    public function test_alle_acht_datei_felder_des_alten_portals_sind_abgedeckt(): void
    {
        // Die acht FILE_FIELDS aus EmployeePortal.php:93-102. Faellt hier eines
        // heraus, bekaeme es im neuen Portal eine Kachel ohne Ziel — dieselbe
        // Luecke wie E7, nur andersherum.
        $acht = [
            'identity_card_front_file_id', 'identity_card_back_file_id',
            'selfie_file_id', 'health_insurance_card_file_id',
            'immatrikulation_file_id', 'schulbescheinigung_file_id',
            'erstbescheinigung_file_id', 'first_aider_certificate_file_id',
        ];
        foreach ($acht as $spalte) {
            $this->assertNotNull(ProofTypes::codeForLegacyColumn($spalte), "Keine Nachweisart fuer {$spalte}");
        }
    }

    public function test_alle_altspalten_sind_eindeutig(): void
    {
        $alle = ProofTypes::legacyFileColumnsAll();
        $this->assertSame(array_values(array_unique($alle)), $alle);
    }
}
