<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobVersandweg;

class DispoJobVersandwegTest extends TestCase
{
    public function test_waehlbar_nur_bei_schalter_an_und_monats_oder_wochenanlass(): void
    {
        $this->assertTrue(DispoJobVersandweg::waehlbar(true, 'vier_wochen'));
        $this->assertTrue(DispoJobVersandweg::waehlbar(true, 'naechste_woche'));
        $this->assertFalse(DispoJobVersandweg::waehlbar(true, 'dringend'));
        foreach (['vier_wochen', 'naechste_woche', 'dringend'] as $anlass) {
            $this->assertFalse(DispoJobVersandweg::waehlbar(false, $anlass));
        }
    }

    /** @return array<string, array{bool,string,string,string,bool}> */
    public static function anfragen(): array
    {
        $t = [];
        foreach (['vier_wochen', 'naechste_woche', 'dringend'] as $anlass) {
            foreach (['portal', 'whatsapp'] as $wunsch) {
                // Schalter aus: immer der heutige Stand.
                $t["aus/$anlass/$wunsch"] = [false, $anlass, $wunsch, 'whatsapp', false];
            }
            $waehlbar = $anlass !== 'dringend';
            $t["an/$anlass/portal"] = [true, $anlass, 'portal', $waehlbar ? 'portal' : 'whatsapp', false];
            $t["an/$anlass/whatsapp"] = [true, $anlass, 'whatsapp', 'whatsapp', $waehlbar];
        }

        return $t;
    }

    #[DataProvider('anfragen')]
    public function test_fuer_anfrage(bool $aktiv, string $anlass, string $wunsch, string $weg, bool $freigabe): void
    {
        $this->assertSame(['weg' => $weg, 'freigabe_pflicht' => $freigabe], DispoJobVersandweg::fuerAnfrage($aktiv, $anlass, $wunsch));
    }

    public function test_ist_freigeber_vergleicht_getrimmt_und_kleingeschrieben(): void
    {
        $s = new \Platform\Recruiting\Models\RecApplicantSettings();
        $s->setSetting(DispoJobVersandweg::SETTING_FREIGEBER, 'markus@rg.de');
        $this->assertTrue(DispoJobVersandweg::istFreigeber($s, (object) ['email' => ' Markus@RG.de ']));
        $this->assertFalse(DispoJobVersandweg::istFreigeber($s, (object) ['email' => 'anna@rg.de']));
        $this->assertFalse(DispoJobVersandweg::istFreigeber($s, (object) ['email' => '']));
        $this->assertFalse(DispoJobVersandweg::istFreigeber($s, null));
        // Ohne hinterlegten Freigeber ist niemand Freigeber (auch nicht der leere Vergleich).
        $this->assertFalse(DispoJobVersandweg::istFreigeber(new \Platform\Recruiting\Models\RecApplicantSettings(), (object) ['email' => '']));
    }

    public function test_freigeber_email_und_aktiv_aus_den_einstellungen(): void
    {
        $s = new \Platform\Recruiting\Models\RecApplicantSettings();
        $this->assertFalse(DispoJobVersandweg::aktiv($s));
        $this->assertNull(DispoJobVersandweg::freigeberEmail($s));
        $s->setSetting(DispoJobVersandweg::SETTING_AKTIV, true);
        $s->setSetting(DispoJobVersandweg::SETTING_FREIGEBER, '  Markus@RG.de ');
        $this->assertTrue(DispoJobVersandweg::aktiv($s));
        $this->assertSame('markus@rg.de', DispoJobVersandweg::freigeberEmail($s));
        $s->setSetting(DispoJobVersandweg::SETTING_FREIGEBER, '   ');
        $this->assertNull(DispoJobVersandweg::freigeberEmail($s));
    }

    public function test_darf_einstellungen_aendern(): void
    {
        $this->assertTrue(DispoJobVersandweg::darfEinstellungenAendern(null, (object) ['email' => 'irgendwer@rg.de']));
        $this->assertTrue(DispoJobVersandweg::darfEinstellungenAendern('markus@rg.de', (object) ['email' => ' MARKUS@rg.de']));
        $this->assertFalse(DispoJobVersandweg::darfEinstellungenAendern('markus@rg.de', (object) ['email' => 'anna@rg.de']));
        $this->assertFalse(DispoJobVersandweg::darfEinstellungenAendern('markus@rg.de', null));
    }
}
