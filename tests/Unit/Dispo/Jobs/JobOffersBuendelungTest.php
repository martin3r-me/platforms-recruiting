<?php

namespace Platform\Recruiting\Tests\Unit\Dispo\Jobs;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\Jobs\JobOffersBuendelung;

/** Spec §9: dieselbe Schicht aus zwei Anfragen steht auf der Seite nur einmal. */
final class JobOffersBuendelungTest extends TestCase
{
    private static function row(int $id, array $extra = []): array
    {
        return $extra + ['offer_shift_id' => $id, 'event' => 1, 'datum' => '2026-10-24', 'von' => '17:00', 'taetigkeit' => 'Zapfer',
            'accepted' => false, 'closed' => false, 'zuerst' => true, 'frei' => 3, 'zurueck' => false, 'eingetragen' => false];
    }

    public function testGleicheSchichtAusZweiAnfragenErscheintEinmalMitKleinsterId(): void
    {
        $out = JobOffersBuendelung::buendeln([self::row(9, ['frei' => 4]), self::row(7, ['frei' => 2, 'taetigkeit' => ' zapfer'])]);

        $this->assertCount(1, $out);
        $this->assertSame(7, $out[0]['offer_shift_id']);
        $this->assertSame([7, 9], $out[0]['offer_shift_ids']);
        $this->assertSame(2, $out[0]['frei']);
        $this->assertFalse($out[0]['accepted']);
        $this->assertFalse($out[0]['voll']);
    }

    public function testZusageInEinerAnfrageZaehltFuerBeide(): void
    {
        $out = JobOffersBuendelung::buendeln([self::row(7), self::row(9, ['accepted' => true, 'closed' => true])]);

        $this->assertCount(1, $out);
        $this->assertTrue($out[0]['accepted']);
        $this->assertFalse($out[0]['voll']);
    }

    public function testVolleZeileGibtDenKnopfAnDieOffeneAb(): void
    {
        $out = JobOffersBuendelung::buendeln([self::row(7, ['closed' => true, 'frei' => 0]), self::row(9, ['zuerst' => false, 'frei' => null])]);

        $this->assertSame(9, $out[0]['offer_shift_id'], 'Knopf wirkt auf die noch offene Angebots-Schicht');
        $this->assertFalse($out[0]['voll']);
        $this->assertNull($out[0]['frei'], 'Sammel-Anfrage ohne Zaehler: kein „noch N frei“ aus der vollen Zeile');
    }

    public function testAlleVollIstVoll(): void
    {
        $out = JobOffersBuendelung::buendeln([self::row(7, ['closed' => true]), self::row(9, ['closed' => true])]);

        $this->assertTrue($out[0]['voll']);
        $this->assertSame(7, $out[0]['offer_shift_id']);
    }

    public function testVerschiedeneSchichtenBleibenGetrennt(): void
    {
        $out = JobOffersBuendelung::buendeln([
            self::row(1),
            self::row(2, ['event' => 2]),
            self::row(3, ['datum' => '2026-10-25']),
            self::row(4, ['von' => '18:00']),
            self::row(5, ['taetigkeit' => 'Spüler']),
        ]);

        $this->assertSame([1, 2, 3, 4, 5], array_column($out, 'offer_shift_id'));
    }

    /** „Doch nicht“ zielt auf die zugesagte Zeile, nicht auf die (evtl. offene) Repraesentantin. */
    public function testRuecknahmeZieltAufDieZugesagteZeile(): void
    {
        $out = JobOffersBuendelung::buendeln([
            self::row(7),
            self::row(9, ['accepted' => true, 'zurueck' => true]),
            self::row(8, ['accepted' => true, 'zurueck' => true]),
        ]);

        $this->assertSame(7, $out[0]['offer_shift_id'], 'Repraesentantin ist die offene Zeile');
        $this->assertTrue($out[0]['zurueck']);
        $this->assertSame(8, $out[0]['zurueck_id'], 'kleinste zuruecknehmbare Angebots-Schicht');
    }

    public function testOhneRuecknehmbareZeileKeinZiel(): void
    {
        $out = JobOffersBuendelung::buendeln([self::row(7), self::row(9, ['accepted' => true])]);

        $this->assertTrue($out[0]['accepted']);
        $this->assertFalse($out[0]['zurueck'], 'zugesagt, aber eingetragen');
        $this->assertNull($out[0]['zurueck_id']);
    }

    public function testEingetrageneSchwesterSperrtDieRuecknahmeDerGanzenGruppe(): void
    {
        // Eine Schwester ist in ZAS eingetragen, die andere nur zugesagt: JobOffersRuecknahme
        // wuerde die ganze Gruppe ablehnen — also kein „Doch nicht?“ zeigen.
        $out = JobOffersBuendelung::buendeln([
            self::row(7, ['accepted' => true, 'eingetragen' => true]),
            self::row(9, ['accepted' => true, 'zurueck' => true]),
        ]);

        $this->assertCount(1, $out);
        $this->assertTrue($out[0]['accepted']);
        $this->assertFalse($out[0]['zurueck']);
        $this->assertNull($out[0]['zurueck_id']);
    }
}
