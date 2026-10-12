<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecApplicantSettings;
use Platform\Recruiting\Support\PortalVorlagen;

final class PortalVorlagenTest extends TestCase
{
    public function test_rumpfplatzhalter_reihenfolge_doppelte_und_nur_body(): void
    {
        $komponenten = [
            ['type' => 'HEADER', 'text' => 'Hallo {{kopf}}'],
            ['type' => 'BODY', 'text' => 'Hi {{Name}}, Code {{code}}, nochmal {{Name}}'],
            ['type' => 'FOOTER', 'text' => '{{fuss}}'],
        ];
        $this->assertSame(['Name', 'code'], PortalVorlagen::rumpfPlatzhalter($komponenten));
        $this->assertSame([], PortalVorlagen::rumpfPlatzhalter([]));
    }

    public function test_knopf_mit_parameter_alle_faelle(): void
    {
        $knoepfe = fn (array $k) => [['type' => 'BODY', 'text' => 'x'], ['type' => 'BUTTONS', 'buttons' => $k]];

        $this->assertFalse(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'URL', 'url' => 'https://a.de/x']])));
        $this->assertTrue(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'URL', 'url' => 'https://a.de/{{1}}']])));
        $this->assertTrue(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'COPY_CODE', 'example' => 'X']])));
        $this->assertTrue(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'otp']])));
        $this->assertFalse(PortalVorlagen::hatKnopfMitParameter($knoepfe([['type' => 'QUICK_REPLY', 'text' => 'ok']])));
        $this->assertFalse(PortalVorlagen::hatKnopfMitParameter([['type' => 'BODY', 'text' => 'x']]));
        $this->assertFalse(PortalVorlagen::hatKnopfMitParameter([]));
    }

    public function test_vorgaben_stehen_in_default_settings(): void
    {
        foreach ([PortalVorlagen::CODE_PASSWORT, PortalVorlagen::CODE_NUMMERNWECHSEL, PortalVorlagen::CODE_NOTFALL,
            PortalVorlagen::HINWEIS_ALTE_NUMMER, PortalVorlagen::AUFGABEN_NEU, PortalVorlagen::AUFGABEN_ERINNERUNG] as $schluessel) {
            $this->assertArrayHasKey($schluessel, RecApplicantSettings::DEFAULT_SETTINGS);
            $this->assertNull(RecApplicantSettings::DEFAULT_SETTINGS[$schluessel]);
        }
        $this->assertFalse(RecApplicantSettings::DEFAULT_SETTINGS[PortalVorlagen::EINSATZ_PRUEFUNG_AKTIV]);
    }
}
