<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobAnlass;

final class DispoJobAnlassTest extends TestCase
{
    public function testDreiFesteAnlaesseInDieserReihenfolge(): void
    {
        $this->assertSame(
            ['vier_wochen' => 'Nächste vier Wochen', 'naechste_woche' => 'Nächste Woche', 'dringend' => 'Dringend'],
            DispoJobAnlass::ALLE
        );
    }

    public function testSettingsKeyJeAnlass(): void
    {
        $this->assertSame('dispo_job_template_vier_wochen_id', DispoJobAnlass::settingsKey('vier_wochen'));
        $this->assertSame('dispo_job_template_naechste_woche_id', DispoJobAnlass::settingsKey('naechste_woche'));
        $this->assertSame('dispo_job_template_dringend_id', DispoJobAnlass::settingsKey('dringend'));
    }

    public function testLabelUnbekannterAnlassIstDerSchluessel(): void
    {
        $this->assertSame('Dringend', DispoJobAnlass::label('dringend'));
        $this->assertSame('irgendwas', DispoJobAnlass::label('irgendwas'));
    }

    public function testIstGueltig(): void
    {
        $this->assertTrue(DispoJobAnlass::istGueltig('naechste_woche'));
        $this->assertFalse(DispoJobAnlass::istGueltig(''));
        $this->assertFalse(DispoJobAnlass::istGueltig('Dringend'));
        $this->assertFalse(DispoJobAnlass::istGueltig('dispo_job_template_dringend_id'));
    }

    public function testTemplateIdsLiestDieDreiSchluessel(): void
    {
        $s = new RecApplicantSettings();
        $s->settings = [
            'dispo_job_template_vier_wochen_id'    => 12,
            'dispo_job_template_naechste_woche_id' => null,
            'dispo_job_template_dringend_id'       => '7',
            'dispo_job_offer_template_ids'         => [99],
        ];

        $this->assertSame(['vier_wochen' => 12, 'naechste_woche' => null, 'dringend' => 7], DispoJobAnlass::templateIds($s));
    }

    public function testTemplateIdsIgnoriertMuell(): void
    {
        $s = new RecApplicantSettings();
        $s->settings = [
            'dispo_job_template_vier_wochen_id'    => 0,
            'dispo_job_template_naechste_woche_id' => 'x',
            'dispo_job_template_dringend_id'       => -3,
        ];

        $this->assertSame(['vier_wochen' => null, 'naechste_woche' => null, 'dringend' => null], DispoJobAnlass::templateIds($s));
    }
}
