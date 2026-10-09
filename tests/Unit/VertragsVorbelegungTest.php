<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\VertragsVorbelegung;

final class VertragsVorbelegungTest extends TestCase
{
    public function test_monatserster_und_monatsletzter(): void
    {
        $this->assertSame(['beginn' => '2026-10-01', 'ende' => '2026-10-31'], VertragsVorbelegung::fuerEinsatz('2026-10-20'));
        $this->assertSame(['beginn' => '2028-02-01', 'ende' => '2028-02-29'], VertragsVorbelegung::fuerEinsatz('2028-02-10'), 'Schaltjahr');
        $this->assertNull(VertragsVorbelegung::fuerEinsatz('20.10.2026'));
        $this->assertNull(VertragsVorbelegung::fuerEinsatz(''));
    }

    public function test_notiz_und_rueckweg(): void
    {
        $notiz = VertragsVorbelegung::notiz('2026-10-20', 'Messe Düsseldorf', 'Service', 'MA');

        $this->assertSame(
            'MA-Einsatz am 20.10.2026 (Messe Düsseldorf, Service) — kein unterschriebener Arbeitsvertrag der Gesellschaft MA deckt diesen Tag.',
            $notiz
        );
        $this->assertSame('2026-10-20', VertragsVorbelegung::einsatztagAusNotiz($notiz));
    }

    public function test_notiz_ohne_event_und_taetigkeit(): void
    {
        $this->assertSame(
            'MA-Einsatz am 03.11.2026 — kein unterschriebener Arbeitsvertrag der Gesellschaft MA deckt diesen Tag.',
            VertragsVorbelegung::notiz('2026-11-03', null, '  ', 'MA')
        );
    }

    public function test_fremde_notiz_liefert_keinen_tag(): void
    {
        $this->assertNull(VertragsVorbelegung::einsatztagAusNotiz('Einsatz-Pruefung: Arbeitserlaubnis fehlt.'));
        $this->assertNull(VertragsVorbelegung::einsatztagAusNotiz(null));
        $this->assertNull(VertragsVorbelegung::einsatztagAusNotiz('MA-Einsatz am 31.02.2026'));
    }

    public function test_deutsch_ist_die_eine_datumsformatierung(): void
    {
        $this->assertSame('20.10.2026', VertragsVorbelegung::deutsch('2026-10-20'));
    }
}
