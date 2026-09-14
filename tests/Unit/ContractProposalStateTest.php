<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\ContractProposalState;

/**
 * Der Zustand des Schulungsleiter-Vorschlags (Lohn + Vertragslaufzeit) als
 * reine Funktion — eine Quelle fuer die Nachbereitung (zeigt dem
 * Schulungsleiter, ob sein Vorschlag schon angekommen ist) und den
 * HR-Schreibtisch (zeigt Clara den "Uebernehmen"-Knopf).
 *
 * Warum es die Funktion gibt: Vorschlag und scharfer Wert sind zwei Felder,
 * und der gefaehrliche Fall ist der Vertipper NACH der Uebernahme. Korrigiert
 * der Schulungsleiter seine Zahl, nachdem HR sie uebernommen hat, darf das
 * nicht still auseinanderlaufen — der Vorschlag muss von allein wieder als
 * "nicht uebernommen" gelten. Entschieden wird das ausschliesslich ueber die
 * beiden Zeitstempel, nicht ueber ein Flag, das jemand zu setzen vergisst.
 */
class ContractProposalStateTest extends TestCase
{
    public function test_korrektur_nach_uebernahme_gilt_wieder_als_offen(): void
    {
        // Clara hat um 10:00 uebernommen, Daniel merkt um 11:00 seinen
        // Vertipper und korrigiert. Ohne diesen Rueckfall wuerde der alte
        // Wert rausgehen und niemand saehe es.
        $this->assertSame('changed', ContractProposalState::state(
            proposedAt: 1100,
            takenAt: 1000,
            hasSent: false,
        ));
    }

    public function test_uebernommener_vorschlag_bleibt_uebernommen(): void
    {
        // Daniel schlaegt um 09:00 vor, Clara uebernimmt um 10:00 und
        // ruehrt danach niemand mehr an.
        $this->assertSame('taken', ContractProposalState::state(
            proposedAt: 900,
            takenAt: 1000,
            hasSent: false,
        ));
    }

    public function test_noch_nicht_uebernommener_vorschlag_ist_offen(): void
    {
        // Der Normalfall direkt nach der Schulung: Vorschlag liegt da,
        // HR hat ihn noch nicht angefasst.
        $this->assertSame('open', ContractProposalState::state(
            proposedAt: 900,
            takenAt: null,
            hasSent: false,
        ));
    }

    public function test_ohne_vorschlag_gibt_es_nichts_anzuzeigen(): void
    {
        // Die grosse Mehrheit der Faelle: niemand hat etwas vorgeschlagen.
        // HR darf hier keinen "Uebernehmen"-Knopf sehen.
        $this->assertSame('none', ContractProposalState::state(
            proposedAt: null,
            takenAt: null,
            hasSent: false,
        ));
    }

    public function test_nach_dem_versand_ist_der_vorschlag_nur_noch_historie(): void
    {
        // Sind die Vertraege raus, darf eine alte Empfehlung nicht mehr
        // wie eine offene Aufgabe aussehen — sonst haelt sie beim
        // Neu-Ausstellen jemand fuer den aktuellen Stand.
        $this->assertSame('archived', ContractProposalState::state(
            proposedAt: 900,
            takenAt: 1000,
            hasSent: true,
        ));
    }

    public function test_nach_dem_versand_auch_wenn_hr_nie_uebernommen_hat(): void
    {
        // Clara hat eigene Werte getippt und versendet, ohne den Vorschlag
        // anzufassen. Auch dann ist er erledigt, nicht offen.
        $this->assertSame('archived', ContractProposalState::state(
            proposedAt: 900,
            takenAt: null,
            hasSent: true,
        ));
    }

    public function test_gleicher_zeitstempel_gilt_als_uebernommen(): void
    {
        // Bewusste Festlegung fuer den Sekunden-Gleichstand: Uebernahme
        // gewinnt. Andernfalls saehe Clara direkt nach ihrem eigenen Klick
        // wieder einen offenen Vorschlag.
        $this->assertSame('taken', ContractProposalState::state(
            proposedAt: 1000,
            takenAt: 1000,
            hasSent: false,
        ));
    }
}
