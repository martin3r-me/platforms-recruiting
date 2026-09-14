<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\DefaultContractTemplateAssignment;

/**
 * "Ab Status Teilgenommen wird die AV-default-Vorlage zugewiesen" — die Regel
 * als reine Entscheidung, damit sie nicht zweimal existiert.
 *
 * Sie wird ab 14.09.2026 von zwei Oberflaechen ausgeloest: der grossen
 * Nachbereitung und der schlanken Teamleiter-Ansicht. Liefe sie in der einen
 * und nicht in der anderen, haetten Teilnehmer je nach Bediener eine Vorlage
 * oder keine — und der Vertragsversand faende spaeter nichts vor.
 */
class DefaultContractTemplateAssignmentTest extends TestCase
{
    public function test_ohne_vorlage_wird_die_standardvorlage_gesetzt(): void
    {
        $this->assertSame(7, DefaultContractTemplateAssignment::resolve(
            currentTemplateId: null,
            defaultTemplateId: 7,
        ));
    }

    public function test_bereits_gesetzte_vorlage_wird_nicht_ueberschrieben(): void
    {
        // Sonst wuerde ein zweiter Klick auf "Teilgenommen" eine bewusst
        // gewaehlte Zuschlags-Variante wieder auf default zuruecksetzen.
        $this->assertNull(DefaultContractTemplateAssignment::resolve(
            currentTemplateId: 3,
            defaultTemplateId: 7,
        ));
    }

    public function test_ohne_standardvorlage_passiert_nichts(): void
    {
        $this->assertNull(DefaultContractTemplateAssignment::resolve(
            currentTemplateId: null,
            defaultTemplateId: null,
        ));
    }
}
