<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZeitfenster;

/** Zeitraum je Anlass (Spec 2026-10-30 §1) — rein, ohne Uhr. */
final class DispoJobZeitfensterTest extends TestCase
{
    public function testDringendIstMorgen(): void
    {
        $this->assertSame(['von' => '2026-10-31', 'bis' => '2026-10-31'], DispoJobZeitfenster::fuer('dringend', new \DateTime('2026-10-30 23:59:00')));
    }

    public function testNaechsteWocheIstMontagBisSonntagNachHeute(): void
    {
        $freitag = CarbonImmutable::parse('2026-10-30');
        $this->assertSame(['von' => '2026-11-02', 'bis' => '2026-11-08'], DispoJobZeitfenster::fuer('naechste_woche', $freitag));
        // Sonntag: der naechste Montag ist morgen, nicht in acht Tagen.
        $this->assertSame(['von' => '2026-11-02', 'bis' => '2026-11-08'], DispoJobZeitfenster::fuer('naechste_woche', CarbonImmutable::parse('2026-11-01')));
        // Montag: streng NACH heute, also naechste Woche.
        $this->assertSame(['von' => '2026-11-09', 'bis' => '2026-11-15'], DispoJobZeitfenster::fuer('naechste_woche', CarbonImmutable::parse('2026-11-02')));
    }

    public function testVierWochenEndetAmMonatsendeDesVierWochenTages(): void
    {
        // Fr 30.10. → Mo 02.11., Tag 28 = 29.11. → bis 30.11.
        $this->assertSame(['von' => '2026-11-02', 'bis' => '2026-11-30'], DispoJobZeitfenster::fuer('vier_wochen', CarbonImmutable::parse('2026-10-30')));
        // Mo 23.11. → Mo 30.11., Tag 28 = 27.12. → bis 31.12. (Markus-Frage: nie nur ein Tag)
        $this->assertSame(['von' => '2026-11-30', 'bis' => '2026-12-31'], DispoJobZeitfenster::fuer('vier_wochen', CarbonImmutable::parse('2026-11-23')));
        // Mi 04.11. → Mo 09.11., Tag 28 = 06.12. → bis 31.12.
        $this->assertSame(['von' => '2026-11-09', 'bis' => '2026-12-31'], DispoJobZeitfenster::fuer('vier_wochen', CarbonImmutable::parse('2026-11-04')));
    }

    public function testUnbekannterAnlassBelegtNichtsVor(): void
    {
        $this->assertNull(DispoJobZeitfenster::fuer('', CarbonImmutable::parse('2026-10-30')));
        $this->assertNull(DispoJobZeitfenster::fuer('irgendwas', CarbonImmutable::parse('2026-10-30')));
        $this->assertNull(DispoJobZeitfenster::hinweis('', CarbonImmutable::parse('2026-10-30')));
    }

    public function testHinweisNenntAnlassUndTage(): void
    {
        $heute = CarbonImmutable::parse('2026-10-30');
        $this->assertSame('Vorgeschlagen für „Nächste Woche“: Mo 02.11. bis So 08.11. Du kannst den Zeitraum ändern.', DispoJobZeitfenster::hinweis('naechste_woche', $heute));
        $this->assertSame('Vorgeschlagen für „Dringend“: Sa 31.10. Du kannst den Zeitraum ändern.', DispoJobZeitfenster::hinweis('dringend', $heute));
        $this->assertSame('Vorgeschlagen für „Nächste vier Wochen“: Mo 02.11. bis Mo 30.11. Du kannst den Zeitraum ändern.', DispoJobZeitfenster::hinweis('vier_wochen', $heute));
    }
}
