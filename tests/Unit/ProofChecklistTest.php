<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ProofChecklist;

/**
 * Die Aufgabenliste ist das Erste, was der Mitarbeiter im Portal sieht.
 * Steht dort Falsches, laedt er etwas hoch, das er nicht braucht — oder
 * schlimmer: er sieht nicht, dass seine Arbeitserlaubnis ablaeuft.
 */
final class ProofChecklistTest extends TestCase
{
    private const HEUTE = '2026-09-24';

    private function nachweis(string $code, ?string $bis = null): array
    {
        return ['proof_type_code' => $code, 'valid_until' => $bis];
    }

    public function test_pflicht_ohne_nachweis_fehlt(): void
    {
        $liste = ProofChecklist::build(['ausweis'], [], self::HEUTE);

        $this->assertCount(1, $liste);
        $this->assertSame(ProofChecklist::FEHLT, $liste[0]['status']);
        $this->assertTrue($liste[0]['offen']);
        $this->assertSame('Personalausweis oder Reisepass', $liste[0]['label']);
    }

    public function test_gueltiger_nachweis_ist_ok(): void
    {
        $liste = ProofChecklist::build(['ausweis'], [$this->nachweis('ausweis', '2030-01-01')], self::HEUTE);

        $this->assertSame(ProofChecklist::OK, $liste[0]['status']);
        $this->assertFalse($liste[0]['offen']);
    }

    public function test_abgelaufen(): void
    {
        $liste = ProofChecklist::build(['ausweis'], [$this->nachweis('ausweis', '2026-09-23')], self::HEUTE);

        $this->assertSame(ProofChecklist::ABGELAUFEN, $liste[0]['status']);
    }

    public function test_laeuft_ab_richtet_sich_nach_der_vorlaufzeit_der_art(): void
    {
        $in45Tagen = '2026-11-08';

        $titel = ProofChecklist::build(['aufenthaltstitel'], [$this->nachweis('aufenthaltstitel', $in45Tagen)], self::HEUTE);
        $ausweis = ProofChecklist::build(['ausweis'], [$this->nachweis('ausweis', $in45Tagen)], self::HEUTE);

        $this->assertSame(ProofChecklist::LAEUFT_AB, $titel[0]['status'], '60 Tage Vorlauf');
        $this->assertSame(ProofChecklist::OK, $ausweis[0]['status'], '30 Tage Vorlauf — noch kein Thema');
    }

    public function test_arten_ohne_ablauf_sind_ok_sobald_sie_da_sind(): void
    {
        $liste = ProofChecklist::build(['ausweis'], [$this->nachweis('selfie')], self::HEUTE);

        $selfie = current(array_filter($liste, fn ($z) => $z['code'] === 'selfie'));
        $this->assertSame(ProofChecklist::OK, $selfie['status']);
    }

    public function test_zeigt_auch_nachweise_die_nicht_pflicht_sind(): void
    {
        $liste = ProofChecklist::build(['ausweis'], [$this->nachweis('nationalpass', '2031-01-01')], self::HEUTE);

        $codes = array_column($liste, 'code');
        $this->assertContains('nationalpass', $codes, 'es sind seine Unterlagen, er soll sie sehen');
        $this->assertContains('ausweis', $codes);
    }

    public function test_offenes_steht_oben_dringendes_zuerst(): void
    {
        $liste = ProofChecklist::build(
            ['ausweis', 'aufenthaltstitel', 'arbeitsgenehmigung'],
            [
                $this->nachweis('ausweis', '2030-01-01'),          // ok
                $this->nachweis('aufenthaltstitel', '2026-09-01'),  // abgelaufen
                $this->nachweis('arbeitsgenehmigung', '2026-10-15'),// laeuft ab
            ],
            self::HEUTE,
        );

        $this->assertSame(
            [ProofChecklist::ABGELAUFEN, ProofChecklist::LAEUFT_AB, ProofChecklist::OK],
            array_column($liste, 'status'),
        );
    }

    public function test_fehlendes_steht_vor_ablaufendem(): void
    {
        $liste = ProofChecklist::build(
            ['ausweis', 'aufenthaltstitel'],
            [$this->nachweis('aufenthaltstitel', '2026-10-15')],
            self::HEUTE,
        );

        $this->assertSame(ProofChecklist::FEHLT, $liste[0]['status']);
        $this->assertSame('ausweis', $liste[0]['code']);
    }

    public function test_zaehlt_die_offenen_punkte(): void
    {
        $liste = ProofChecklist::build(
            ['ausweis', 'aufenthaltstitel'],
            [$this->nachweis('ausweis', '2030-01-01')],
            self::HEUTE,
        );

        $this->assertSame(1, ProofChecklist::offeneAnzahl($liste));
    }

    public function test_unbekannte_arten_werden_ignoriert(): void
    {
        $liste = ProofChecklist::build(['gibtsnicht'], [$this->nachweis('auchnicht')], self::HEUTE);

        $this->assertSame([], $liste);
    }

    public function test_am_ablauftag_selbst_noch_nicht_abgelaufen(): void
    {
        $liste = ProofChecklist::build(['ausweis'], [$this->nachweis('ausweis', self::HEUTE)], self::HEUTE);

        $this->assertSame(ProofChecklist::LAEUFT_AB, $liste[0]['status'], 'heute noch gueltig, aber dringend');
    }

    /**
     * Ein Nachweis mit einer Art, die MIT Ablauf gilt (anders als
     * selfie/krankenkasse, die von Haus aus keinen Ablauf kennen), kann
     * trotzdem ohne Datum in der Tabelle stehen: Der Umzug der Altdaten
     * uebernimmt eine leere Spalte unveraendert — es gibt seit dem Revert vom
     * 24.09.2026 keinen Weg mehr, das ueber das Portal ("unbefristet"-Haekchen,
     * verworfen) neu zu erzeugen. Dieser Zustand kann also weiterhin
     * entstehen und muss weiterhin als OK gelten, nicht als offene Aufgabe.
     */
    public function test_nachweis_ohne_datum_aus_altbestand_gilt_als_ok(): void
    {
        $liste = ProofChecklist::build(['aufenthaltstitel'], [$this->nachweis('aufenthaltstitel', null)], self::HEUTE);

        $this->assertSame(ProofChecklist::OK, $liste[0]['status']);
        $this->assertFalse($liste[0]['offen']);
        $this->assertNull($liste[0]['valid_until']);
    }
}
