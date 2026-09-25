<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ShortTermDayBudget;

/**
 * "Tage erlaubt" — der Startwert, ab dem ZAS das Tageskonto herunterzaehlt
 * (Markus 24.09.2026: 20 bereits gearbeitet, Grenze 70, also 50).
 *
 * WAS HIER AUF DEM SPIEL STEHT: Eine zu hohe Zahl heisst, dass jemand mehr
 * Tage arbeitet als erlaubt — die kurzfristige Beschaeftigung verliert dann
 * rueckwirkend ihren Status. Deshalb ist diese Klasse durchgehend streng:
 * im Zweifel KEIN Wert statt eines geratenen.
 *
 * DREIWERTIG: null heisst "keine Grundlage", 0 heisst "Grenze
 * ausgeschoepft". Die beiden duerfen nie verwechselt werden.
 */
final class ShortTermDayBudgetTest extends TestCase
{
    private const GRENZE = 70;

    /** 25.09.2026 — Bezugspunkt fuer das laufende Kalenderjahr. */
    private function heute(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-25');
    }

    private function rechne(array $data, int $limit = self::GRENZE): ?int
    {
        return ShortTermDayBudget::allowedFrom($data, $limit, $this->heute());
    }

    private function mitTagen(array $entries): array
    {
        return ['par15_has_previous' => true, 'par15_entries' => $entries];
    }

    public function test_beispiel_aus_markus_mail(): void
    {
        $this->assertSame(50, $this->rechne($this->mitTagen([
            ['beginn' => '2026-01-05', 'ende' => '2026-02-10', 'arbeitgeber' => 'Event GmbH', 'tage' => 20],
        ])));
    }

    public function test_mehrere_eintraege_werden_summiert(): void
    {
        $this->assertSame(55, $this->rechne($this->mitTagen([
            ['beginn' => '2026-01-05', 'ende' => '2026-01-20', 'tage' => 10],
            ['beginn' => '2026-03-01', 'ende' => '2026-03-08', 'tage' => 5],
        ])));
    }

    public function test_ausdrueckliches_nein_ergibt_die_volle_grenze(): void
    {
        $this->assertSame(70, $this->rechne(['par15_has_previous' => false, 'par15_entries' => []]));
        $this->assertSame(70, $this->rechne(['par15_has_previous' => 0]));
        $this->assertSame(70, $this->rechne(['par15_has_previous' => '0']));
    }

    public function test_ohne_erklaerung_gibt_es_keinen_startwert(): void
    {
        $this->assertNull($this->rechne([]));
        $this->assertNull($this->rechne(['type' => 'resttage', 'resttage' => 12]));
        $this->assertNull($this->rechne(['par16_was_jobseeking' => false]));
    }

    /**
     * Der Schluessel ist da, sagt aber nichts Eindeutiges. Das ist keine
     * Erklaerung — und darf nicht als "nein, also volle Grenze" durchgehen.
     */
    public function test_unklare_antwort_ist_keine_grundlage(): void
    {
        foreach ([null, '', '   ', 'vielleicht', []] as $wert) {
            $this->assertNull($this->rechne(['par15_has_previous' => $wert]), var_export($wert, true));
        }
    }

    public function test_ja_ohne_eintraege_ist_keine_grundlage(): void
    {
        $this->assertNull($this->rechne($this->mitTagen([])));
    }

    /**
     * DER GEFAEHRLICHSTE FALL (Befund Review 25.09.2026): Eine negative Zahl
     * ERHOEHTE das Budget ueber die Grenze. Gemessen: -30 ergab 100 erlaubte
     * Tage — 30 Tage ueber der gesetzlichen Grenze, und der Wert passt in die
     * Spalte, waere also wirklich so an ZAS gegangen.
     *
     * Die Maske laesst nur `integer|min:1` zu; alles andere ist eine
     * Erklaerung, der wir nicht trauen koennen.
     */
    public function test_negative_tage_erhoehen_das_budget_nicht(): void
    {
        $this->assertNull($this->rechne($this->mitTagen([['tage' => -5]])));
        $this->assertNull($this->rechne($this->mitTagen([['tage' => -30]])));
        $this->assertNull($this->rechne($this->mitTagen([['tage' => 20], ['tage' => -5]])));
        $this->assertNull($this->rechne($this->mitTagen([['tage' => 0]])));
    }

    /**
     * Zahlen in anderer Schreibweise wurden bisher still verschluckt: aus
     * "20 Tage weg" wurde "volle Grenze frei". Auch das ist eine geratene
     * Zahl — lieber gar keine.
     */
    public function test_unlesbare_tageswerte_entwerten_die_erklaerung(): void
    {
        foreach ([20.0, '20.0', '+20', '7,5', true, ['20'], '', null, 'zwanzig'] as $wert) {
            $this->assertNull(
                $this->rechne($this->mitTagen([['tage' => $wert]])),
                var_export($wert, true) . ' ist keine verlaessliche Tageszahl',
            );
        }
    }

    public function test_eintrag_ohne_tageszahl_entwertet_die_erklaerung(): void
    {
        $this->assertNull($this->rechne($this->mitTagen([
            ['beginn' => '2026-01-05', 'ende' => '2026-01-20', 'tage' => 10],
            ['arbeitgeber' => 'ohne Tage'],
        ])));
    }

    public function test_ueberschreitung_ergibt_null_und_nichts_negatives(): void
    {
        $this->assertSame(0, $this->rechne($this->mitTagen([['tage' => 70]])));
        $this->assertSame(0, $this->rechne($this->mitTagen([['tage' => 95]])));
    }

    /**
     * NUR DAS LAUFENDE KALENDERJAHR (Markus 24.09.2026). Die Frage im Vertrag
     * lautet "in den letzten 12 Monaten" — dieses Fenster enthaelt das
     * laufende Kalenderjahr immer vollstaendig, wir koennen also filtern
     * statt den Vertragstext zu aendern.
     *
     * Ohne Filter waere der Fall unten falsch: Unterschrift im Februar 2027,
     * erklaert werden 40 Tage aus 2026 — die zaehlen auf das Kontingent 2026,
     * nicht auf 2027. Der Mitarbeiter duerfte 40 Tage nicht arbeiten, die
     * ihm zustehen.
     */
    public function test_tage_aus_dem_vorjahr_zaehlen_nicht_mit(): void
    {
        $this->assertSame(70, $this->rechne($this->mitTagen([
            ['beginn' => '2025-11-01', 'ende' => '2025-12-20', 'tage' => 40],
        ])));
    }

    public function test_nur_die_tage_des_laufenden_jahres_werden_abgezogen(): void
    {
        $this->assertSame(60, $this->rechne($this->mitTagen([
            ['beginn' => '2025-11-01', 'ende' => '2025-12-20', 'tage' => 40],
            ['beginn' => '2026-03-01', 'ende' => '2026-03-15', 'tage' => 10],
        ])));
    }

    /**
     * Ein Eintrag ueber den Jahreswechsel laesst sich nicht aufteilen — die
     * Tageszahl gilt fuer den ganzen Zeitraum. Wir zaehlen ihn VOLL mit:
     * zu wenig erlaubte Tage ist eine Unbequemlichkeit, zu viele kosten den
     * Status der Beschaeftigung.
     */
    public function test_eintrag_ueber_den_jahreswechsel_zaehlt_voll(): void
    {
        $this->assertSame(60, $this->rechne($this->mitTagen([
            ['beginn' => '2025-12-20', 'ende' => '2026-01-10', 'tage' => 10],
        ])));
    }

    public function test_ohne_lesbares_datum_wird_vorsichtshalber_mitgezaehlt(): void
    {
        $this->assertSame(60, $this->rechne($this->mitTagen([['tage' => 10]])));
        $this->assertSame(60, $this->rechne($this->mitTagen([['ende' => 'unklar', 'tage' => 10]])));
    }

    /**
     * Eine unsinnige Grenze darf keinen Startwert erzeugen. Bei 0 bekaeme
     * sonst JEDER neue Mitarbeiter "Grenze ausgeschoepft" — geschrieben,
     * nicht ausgelassen.
     */
    public function test_unsinnige_grenze_liefert_keinen_startwert(): void
    {
        foreach ([0, -5] as $grenze) {
            $this->assertNull($this->rechne(['par15_has_previous' => false], $grenze), (string) $grenze);
            $this->assertNull($this->rechne($this->mitTagen([['tage' => 10]]), $grenze), (string) $grenze);
        }
    }

    /**
     * Das Kontingent gilt je Kalenderjahr. Der gespeicherte Startwert ist
     * deshalb jahresgebunden: Steht im Maerz 2027 eine 50, war das der
     * Startwert fuer 2026 — eine Zahl, die nicht mehr gilt.
     *
     * Unterschreibt jemand im neuen Jahr einen neuen Arbeitsvertrag, muss
     * neu gerechnet werden. Ein Waechter "nur wenn leer" ist INNERHALB eines
     * Jahres richtig und ueber den Jahreswechsel falsch.
     */
    public function test_gespeichertes_jahr_entscheidet_ueber_neuberechnung(): void
    {
        $this->assertTrue(ShortTermDayBudget::isCurrentYear(2026, $this->heute()));
        $this->assertFalse(ShortTermDayBudget::isCurrentYear(2025, $this->heute()));
        $this->assertFalse(ShortTermDayBudget::isCurrentYear(2027, $this->heute()));
    }

    public function test_ohne_gespeichertes_jahr_wird_gerechnet(): void
    {
        $this->assertFalse(ShortTermDayBudget::isCurrentYear(null, $this->heute()));
        $this->assertFalse(ShortTermDayBudget::isCurrentYear(0, $this->heute()));
    }

    public function test_das_bezugsjahr_ist_abrufbar(): void
    {
        $this->assertSame(2026, ShortTermDayBudget::yearOf($this->heute()));
    }

    public function test_die_grenze_ist_einstellbar(): void
    {
        $this->assertSame(30, $this->rechne($this->mitTagen([
            ['beginn' => '2026-02-01', 'ende' => '2026-02-20', 'tage' => 20],
        ]), 50));
    }
}
