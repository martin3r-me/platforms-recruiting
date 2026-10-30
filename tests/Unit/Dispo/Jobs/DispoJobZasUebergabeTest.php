<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\DispoJobZasUebergabe;

/** Hinweis je Zusage zum Stand in ZAS (Spec 2026-10-30 §5) — rein, ohne DB. */
class DispoJobZasUebergabeTest extends TestCase
{
    private CarbonImmutable $uebergabe;

    protected function setUp(): void
    {
        $this->uebergabe = CarbonImmutable::parse('2026-10-30 14:05:00');
    }

    public function test_angekommen_schlaegt_alles(): void
    {
        $h = DispoJobZasUebergabe::hinweis(true, $this->uebergabe, null, '', '', $this->uebergabe->addHours(3));

        $this->assertSame(['art' => 'angekommen', 'text' => '✓ in ZAS angekommen'], $h);
    }

    public function test_uebergeben_ohne_spaetere_lieferung_ist_grau(): void
    {
        $h = DispoJobZasUebergabe::hinweis(false, $this->uebergabe, $this->uebergabe, '121000', 'RG17502', null);
        $this->assertSame(['art' => 'uebergeben', 'text' => 'an ZAS übergeben 30.10. 14:05'], $h);

        // Lieferung VOR der Uebergabe zaehlt nicht.
        $h = DispoJobZasUebergabe::hinweis(false, $this->uebergabe, $this->uebergabe, '121000', 'RG17502', $this->uebergabe->subMinutes(5));
        $this->assertSame('uebergeben', $h['art']);
    }

    public function test_lieferung_nach_abstand_ohne_ankunft_ist_ausstehend(): void
    {
        $knappDavor = $this->uebergabe->addMinutes(DispoJobZasUebergabe::LIEFERUNG_ABSTAND_MINUTEN - 1);
        $genau = $this->uebergabe->addMinutes(DispoJobZasUebergabe::LIEFERUNG_ABSTAND_MINUTEN);

        $this->assertSame('uebergeben', DispoJobZasUebergabe::hinweis(false, $this->uebergabe, $this->uebergabe, '1', 'RG1', $knappDavor)['art']);
        $h = DispoJobZasUebergabe::hinweis(false, $this->uebergabe, $this->uebergabe, '1', 'RG1', $genau);
        $this->assertSame(['art' => 'ausstehend', 'text' => 'an ZAS übergeben 30.10. 14:05 · noch nicht angekommen'], $h);
    }

    public function test_haken_von_hand_zeigt_nichts(): void
    {
        $this->assertNull(DispoJobZasUebergabe::hinweis(false, null, $this->uebergabe, '', '', $this->uebergabe->addDay()));
    }

    public function test_handarbeit_erklaert_warum(): void
    {
        $this->assertSame(['art' => 'hand', 'text' => 'von Hand eintragen: kein ZAS-Posten'], DispoJobZasUebergabe::hinweis(false, null, null, null, 'RG1', null));
        $this->assertSame(['art' => 'hand', 'text' => 'von Hand eintragen: kein ZAS-Posten'], DispoJobZasUebergabe::hinweis(false, null, null, '  ', 'RG1', null));
        $this->assertSame(['art' => 'hand', 'text' => 'von Hand eintragen: keine Personalnummer'], DispoJobZasUebergabe::hinweis(false, null, null, '121000', '', null));
        $this->assertSame(['art' => 'hand', 'text' => 'von Hand eintragen: keine Personalnummer'], DispoJobZasUebergabe::hinweis(false, null, null, '121000', null, null));
    }

    public function test_exportierbar_und_noch_nicht_abgeholt_zeigt_nichts(): void
    {
        $this->assertNull(DispoJobZasUebergabe::hinweis(false, null, null, '121000', 'RG17502', $this->uebergabe));
    }

    public function test_lieferung_nach_uebergabe_nimmt_auch_mutable_carbon(): void
    {
        $this->assertTrue(DispoJobZasUebergabe::lieferungNachUebergabe(new \DateTime('2026-10-30 14:05:00'), new \DateTime('2026-10-30 15:05:00')));
        $this->assertFalse(DispoJobZasUebergabe::lieferungNachUebergabe(new \DateTime('2026-10-30 14:05:00'), new \DateTime('2026-10-30 15:04:59')));
        $this->assertFalse(DispoJobZasUebergabe::lieferungNachUebergabe(new \DateTime('2026-10-30 14:05:00'), null));
    }
}
