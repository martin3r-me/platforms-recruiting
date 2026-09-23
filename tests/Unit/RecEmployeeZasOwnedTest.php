<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecEmployee;

/**
 * Wer ist ZAS-Bestand? Aus einer ZAS-Lieferung entstanden
 * (rec_zas_inbound_file_id — wird nur bei der Anlage gesetzt) UND keine
 * Bewerbung verknuepft. Mischfaelle (Lieferung + spaeter verknuepfte
 * Bewerbung) zaehlen bewusst NICHT dazu (Entscheidung 23.09.2026).
 */
class RecEmployeeZasOwnedTest extends TestCase
{
    private function employee(array $raw): RecEmployee
    {
        $e = new RecEmployee();
        $e->setRawAttributes($raw);

        return $e;
    }

    public function test_aus_lieferung_ohne_bewerbung_ist_zas_bestand(): void
    {
        $this->assertTrue($this->employee(['rec_zas_inbound_file_id' => 55, 'rec_applicant_id' => null])->isZasOwned());
    }

    public function test_funnel_mitarbeiter_ist_kein_zas_bestand(): void
    {
        $this->assertFalse($this->employee(['rec_zas_inbound_file_id' => null, 'rec_applicant_id' => 900])->isZasOwned());
    }

    public function test_mischfall_ist_kein_zas_bestand(): void
    {
        $this->assertFalse($this->employee(['rec_zas_inbound_file_id' => 55, 'rec_applicant_id' => 900])->isZasOwned());
    }

    /**
     * Der Hinweis in der HR-Akte ("wird in ZAS gepflegt") stimmt nur, solange
     * der Import tatsaechlich ueberschreibt — also nur mit Schalter an.
     */
    public function test_hinweis_nur_bei_zas_bestand_und_eingeschaltetem_ueberschreiben(): void
    {
        $config = new ConfigRepository(['recruiting' => ['zas' => ['inbound_overwrite_zas_owned' => true]]]);
        Container::getInstance()->instance('config', $config);

        $zas = $this->employee(['rec_zas_inbound_file_id' => 55, 'rec_applicant_id' => null]);
        $this->assertTrue($zas->isMaintainedInZas());
        $this->assertFalse($this->employee(['rec_zas_inbound_file_id' => 55, 'rec_applicant_id' => 900])->isMaintainedInZas());

        $config->set('recruiting.zas.inbound_overwrite_zas_owned', false);
        $this->assertFalse($zas->isMaintainedInZas());
    }
}
