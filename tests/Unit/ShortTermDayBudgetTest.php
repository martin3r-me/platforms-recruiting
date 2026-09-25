<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\ShortTermDayBudget;

/**
 * "Tage erlaubt" — der Startwert, ab dem ZAS das Tageskonto herunterzaehlt
 * (Markus 24.09.2026: 20 bereits gearbeitet, Grenze 70, also 50).
 *
 * Gerechnet wird aus der §15-Erklaerung des Arbeitsvertrags, die wir bei
 * jedem Arbeitsvertrag ohnehin einsammeln.
 *
 * DREIWERTIG, und das ist der Kern: null heisst "keine Grundlage". Ein
 * Vertrag ohne §15-Erklaerung (Altvertrag, IFSG) darf NICHT als "0 Tage
 * gearbeitet, also 70 erlaubt" durchgehen — das waere eine erfundene Zahl an
 * einer Stelle, an der ZAS spaeter herunterzaehlt. Wer dagegen ausdruecklich
 * "nein, ich war nicht kurzfristig beschaeftigt" erklaert hat, liefert eine
 * echte Null.
 */
final class ShortTermDayBudgetTest extends TestCase
{
    private const GRENZE = 70;

    public function test_beispiel_aus_markus_mail(): void
    {
        $this->assertSame(50, ShortTermDayBudget::allowedFrom([
            'par15_has_previous' => true,
            'par15_entries'      => [
                ['beginn' => '2026-01-05', 'ende' => '2026-02-10', 'arbeitgeber' => 'Event GmbH', 'tage' => 20],
            ],
        ], self::GRENZE));
    }

    public function test_mehrere_eintraege_werden_summiert(): void
    {
        $this->assertSame(55, ShortTermDayBudget::allowedFrom([
            'par15_has_previous' => true,
            'par15_entries'      => [
                ['tage' => 10],
                ['tage' => 5],
            ],
        ], self::GRENZE));
    }

    /**
     * Ein ausdrueckliches "nein" ist eine echte Angabe: null Tage
     * gearbeitet, also die volle Grenze.
     */
    public function test_ausdrueckliches_nein_ergibt_die_volle_grenze(): void
    {
        $this->assertSame(70, ShortTermDayBudget::allowedFrom([
            'par15_has_previous' => false,
            'par15_entries'      => [],
        ], self::GRENZE));
    }

    /**
     * Keine Erklaerung = keine Grundlage. Erfundene 70 waeren schlimmer als
     * ein leeres Feld: ZAS zaehlt davon herunter.
     */
    public function test_ohne_erklaerung_gibt_es_keinen_startwert(): void
    {
        $this->assertNull(ShortTermDayBudget::allowedFrom([], self::GRENZE));
        $this->assertNull(ShortTermDayBudget::allowedFrom(['type' => 'resttage', 'resttage' => 12], self::GRENZE));
        $this->assertNull(ShortTermDayBudget::allowedFrom(['par16_was_jobseeking' => false], self::GRENZE));
    }

    /**
     * Wer die Grenze schon ausgeschoepft hat, hat null Tage — keine
     * negative Zahl, die ZAS als Guthaben missverstehen koennte.
     */
    public function test_ueberschreitung_ergibt_null_und_nichts_negatives(): void
    {
        $this->assertSame(0, ShortTermDayBudget::allowedFrom([
            'par15_has_previous' => true,
            'par15_entries'      => [['tage' => 70]],
        ], self::GRENZE));

        $this->assertSame(0, ShortTermDayBudget::allowedFrom([
            'par15_has_previous' => true,
            'par15_entries'      => [['tage' => 95]],
        ], self::GRENZE));
    }

    /**
     * "Ja" ohne Zeilen ist unschluessig — die Maske verlangt mindestens
     * einen Eintrag. Kommt es trotzdem vor, ist es keine Grundlage.
     */
    public function test_ja_ohne_eintraege_ist_keine_grundlage(): void
    {
        $this->assertNull(ShortTermDayBudget::allowedFrom([
            'par15_has_previous' => true,
            'par15_entries'      => [],
        ], self::GRENZE));
    }

    public function test_unbrauchbare_tageswerte_zaehlen_nicht_mit(): void
    {
        // Die Maske validiert integer|min:1; defensiv bleiben, weil die
        // Daten aus einem JSON-Feld kommen und Jahre alt sein koennen.
        $this->assertSame(65, ShortTermDayBudget::allowedFrom([
            'par15_has_previous' => true,
            'par15_entries'      => [
                ['tage' => 5],
                ['tage' => ''],
                ['tage' => null],
                ['arbeitgeber' => 'ohne Tage'],
            ],
        ], self::GRENZE));
    }

    public function test_die_grenze_ist_einstellbar(): void
    {
        $this->assertSame(30, ShortTermDayBudget::allowedFrom([
            'par15_has_previous' => true,
            'par15_entries'      => [['tage' => 20]],
        ], 50));
    }
}
