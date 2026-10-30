<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\AltaktenNachweise;

/** Pilot-Feedback Markus 29.10.2026: was in der ZAS-Akte liegt, nicht doppelt anfordern. */
final class AltaktenNachweiseTest extends TestCase
{
    private function zas(array $werte = []): array
    {
        return $werte + ['zas' => true, 'identity_card_valid_until' => null, 'infection_protection_valid_until' => null, 'health_insurance' => null];
    }

    public function test_belegt_nur_was_zas_geliefert_hat(): void
    {
        $this->assertSame(
            ['ausweis' => '2031-05-01', 'erstbescheinigung' => null, 'krankenkasse' => null],
            AltaktenNachweise::vorschlag([$this->zas([
                'identity_card_valid_until' => '2031-05-01',
                'infection_protection_valid_until' => '2027-01-01',
                'health_insurance' => 'AOK',
            ])], []),
        );
        $this->assertSame([], AltaktenNachweise::vorschlag([$this->zas(['health_insurance' => '  '])], []));
    }

    public function test_angabe_aus_der_bewerbung_ist_kein_aktenstueck(): void
    {
        $this->assertSame([], AltaktenNachweise::vorschlag([$this->zas([
            'zas' => false, 'identity_card_valid_until' => '2031-05-01', 'health_insurance' => 'AOK',
        ])], []));
    }

    public function test_ausweis_nimmt_das_spaeteste_datum_der_person(): void
    {
        $r = AltaktenNachweise::vorschlag([
            $this->zas(['identity_card_valid_until' => '2027-01-01']),
            $this->zas(['identity_card_valid_until' => '2030-12-31 00:00:00']),
        ], []);

        $this->assertSame('2030-12-31', $r['ausweis']);
    }

    public function test_abgelaufener_ausweis_wird_mit_datum_vermerkt(): void
    {
        // Das Portal zeigt ihn dann als "abgelaufen" und fragt nach — nicht "fehlt".
        $this->assertSame(['ausweis' => '2020-01-01'], AltaktenNachweise::vorschlag([$this->zas(['identity_card_valid_until' => '2020-01-01'])], []));
    }

    public function test_belegtes_bleibt_unangetastet(): void
    {
        $r = AltaktenNachweise::vorschlag([$this->zas([
            'identity_card_valid_until' => '2031-05-01', 'health_insurance' => 'AOK',
        ])], ['ausweis']);

        $this->assertSame(['krankenkasse' => null], $r);
    }
}
