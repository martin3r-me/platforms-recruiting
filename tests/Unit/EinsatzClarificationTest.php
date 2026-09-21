<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\EinsatzClarification;

class EinsatzClarificationTest extends TestCase
{
    public function test_ohne_haken_ist_nichts_geklaert(): void
    {
        $this->assertFalse(EinsatzClarification::isActive(null, null, '2026-09-21'));
        $this->assertFalse(
            EinsatzClarification::isActive(null, '2026-10-01', '2026-09-21'),
            'Ein Wiedervorlage-Datum ohne gesetzten Haken klaert nichts',
        );
    }

    public function test_haken_ohne_wiedervorlage_gilt_dauerhaft(): void
    {
        $this->assertTrue(EinsatzClarification::isActive('2026-09-21 10:00:00', null, '2029-01-01'));
    }

    public function test_haken_gilt_bis_zum_tag_vor_der_wiedervorlage(): void
    {
        $this->assertTrue(
            EinsatzClarification::isActive('2026-09-21 10:00:00', '2026-10-01', '2026-09-30'),
            'Am Vortag ist der Fall noch geklaert',
        );
    }

    public function test_am_wiedervorlage_tag_steht_die_person_wieder_auf_der_liste(): void
    {
        $this->assertFalse(
            EinsatzClarification::isActive('2026-09-21 10:00:00', '2026-10-01', '2026-10-01'),
            '„wieder anzeigen ab 01.10." heisst: am 01.10. ist sie wieder dran',
        );
        $this->assertFalse(EinsatzClarification::isActive('2026-09-21 10:00:00', '2026-10-01', '2026-11-05'));
    }

    /**
     * Fail-visible: ein unlesbares Datum darf niemanden dauerhaft aus der
     * Arbeitsliste nehmen. Lieber einmal zu viel nachfassen als einen
     * Menschen still verschwinden lassen.
     */
    public function test_unlesbares_wiedervorlage_datum_gilt_als_abgelaufen(): void
    {
        $this->assertFalse(EinsatzClarification::isActive('2026-09-21 10:00:00', '2026-02-30', '2026-09-21'));
        $this->assertFalse(EinsatzClarification::isActive('2026-09-21 10:00:00', 'irgendwann', '2026-09-21'));
    }

    public function test_einsatz_gewinnt_gegen_den_haken(): void
    {
        $this->assertSame(
            'im_einsatz',
            EinsatzClarification::topf('deployed', true),
            'Wer im Einsatz ist, braucht keine Klaerung mehr',
        );
    }

    public function test_geklaerte_verlassen_die_arbeitsliste(): void
    {
        $this->assertSame('geklaert', EinsatzClarification::topf('none', true));
        $this->assertSame('ohne_einsatz', EinsatzClarification::topf('none', false));
    }

    /**
     * „Nicht pruefbar" ist ein anderer Arbeitsauftrag (Mitarbeiter anlegen,
     * Personalnummer nachtragen) — der Haken ist dort nicht vorgesehen und
     * darf den Topf auch dann nicht wechseln, wenn er gesetzt waere.
     */
    public function test_nicht_pruefbar_bleibt_nicht_pruefbar(): void
    {
        $this->assertSame('einsatz_unpruefbar', EinsatzClarification::topf('unverifiable', true));
    }

    public function test_unbekannte_einsatz_lage_hat_keinen_topf(): void
    {
        $this->assertNull(EinsatzClarification::topf(null, true));
        $this->assertNull(EinsatzClarification::topf('quatsch', false));
    }
}
