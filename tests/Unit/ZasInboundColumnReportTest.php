<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasColumnProfiler;
use Platform\Recruiting\Services\Zas\ZasInboundColumnReport;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;

/**
 * Fuellgrad je ZAS-Spalte ueber mehrere Lieferungen.
 *
 * Die beiden Eigenheiten, die den Bericht ueberhaupt aussagekraeftig machen:
 *
 *  - Der Nenner ist SPALTENWEISE. Spalten, die ZAS erst spaeter dazugenommen
 *    hat (StatusMASeit, Ersthelfer), stehen sonst als "1 von 820" da und sehen
 *    wie ein Lieferproblem aus, obwohl sie in ihrer Lieferung vollstaendig sind.
 *  - "Leer" wird getrennt in ALWAYS_EMPTY (Spalte kam, immer ohne Wert → bei
 *    ZAS nachfragen) und MISSING (Spalte kam nie → Formatabsprache). Die beiden
 *    haben unterschiedliche Konsequenzen, also duerfen sie nicht denselben
 *    Bericht-Eintrag teilen.
 */
class ZasInboundColumnReportTest extends TestCase
{
    private const KNOWN    = ['Name', 'StatusMASeit'];
    private const EXPECTED = ['Name', 'UplSelfie', 'Qualifikation', 'StatusMASeit', 'Sternebewertung', '|'];

    /** Zwei Lieferungen, die zweite mit einer zusaetzlichen Spalte. */
    private function deliveries(): array
    {
        return [
            [
                'columns' => ['Name', 'UplSelfie', 'Qualifikation', '|'],
                'rows'    => [
                    ['Name' => 'Ammerer', 'UplSelfie' => '',              'Qualifikation' => '', '|' => '|'],
                    ['Name' => 'Mueller', 'UplSelfie' => 'bilder/2.jpg',  'Qualifikation' => '', '|' => '|'],
                ],
            ],
            [
                'columns' => ['Name', 'UplSelfie', 'Qualifikation', 'StatusMASeit', '|'],
                'rows'    => [
                    ['Name' => 'Schmitz', 'UplSelfie' => '', 'Qualifikation' => '', 'StatusMASeit' => '01.08.2026', '|' => '|'],
                ],
            ],
        ];
    }

    private function reportService(): ZasInboundColumnReport
    {
        return new ZasInboundColumnReport(new ZasColumnProfiler(), new ZasInboundCsvParser());
    }

    private function report(int $maxExamples = 0): array
    {
        $built = $this->reportService()
            ->build($this->deliveries(), self::KNOWN, self::EXPECTED, $maxExamples);

        return array_column($built, null, 'column');
    }

    public function test_counts_filled_values_across_all_deliveries(): void
    {
        $report = $this->report();

        $this->assertSame(3, $report['Name']['filled']);
        $this->assertSame(3, $report['Name']['rows']);
        $this->assertSame(1, $report['UplSelfie']['filled']);
        $this->assertSame(3, $report['UplSelfie']['rows']);
    }

    public function test_denominator_counts_only_deliveries_that_had_the_column(): void
    {
        $report = $this->report();

        // StatusMASeit kam nur in der zweiten Lieferung (1 Zeile) vor.
        $this->assertSame(1, $report['StatusMASeit']['filled']);
        $this->assertSame(1, $report['StatusMASeit']['rows']);
        $this->assertSame(1.0, $report['StatusMASeit']['ratio']);
    }

    public function test_separates_always_empty_from_missing(): void
    {
        $report = $this->report();

        $this->assertSame(ZasInboundColumnReport::STATUS_ALWAYS_EMPTY, $report['Qualifikation']['status']);
        $this->assertSame(3, $report['Qualifikation']['rows']);

        $this->assertSame(ZasInboundColumnReport::STATUS_MISSING, $report['Sternebewertung']['status']);
        $this->assertSame(0, $report['Sternebewertung']['rows']);
        $this->assertSame(0.0, $report['Sternebewertung']['ratio']);

        $this->assertSame(ZasInboundColumnReport::STATUS_FILLED, $report['Name']['status']);
    }

    public function test_marks_whether_the_import_reads_the_column(): void
    {
        $report = $this->report();

        $this->assertTrue($report['Name']['read']);
        $this->assertTrue($report['StatusMASeit']['read']);
        $this->assertFalse($report['UplSelfie']['read']);
        $this->assertFalse($report['Qualifikation']['read']);
    }

    public function test_keeps_delivery_header_order_and_appends_late_and_missing_columns(): void
    {
        $built = $this->reportService()->build($this->deliveries(), self::KNOWN, self::EXPECTED, 0);

        // Header-Reihenfolge der ersten Lieferung zuerst — daran erkennt man
        // einen Spaltenversatz. Danach was spaeter dazukam, zuletzt das Fehlende.
        $this->assertSame(
            ['Name', 'UplSelfie', 'Qualifikation', '|', 'StatusMASeit', 'Sternebewertung'],
            array_column($built, 'column')
        );
    }

    public function test_examples_are_off_by_default_and_capped_when_requested(): void
    {
        $this->assertSame([], $this->report()['Name']['examples']);

        $withExamples = $this->report(2);
        $this->assertSame(['Ammerer', 'Mueller'], $withExamples['Name']['examples']);
        $this->assertSame(['bilder/2.jpg'], $withExamples['UplSelfie']['examples']);
        $this->assertSame([], $withExamples['Qualifikation']['examples']);
    }

    public function test_only_empty_keeps_always_empty_and_missing(): void
    {
        $built    = $this->reportService()->build($this->deliveries(), self::KNOWN, self::EXPECTED, 0);
        $filtered = ZasInboundColumnReport::onlyEmpty($built);

        $this->assertSame(['Qualifikation', 'Sternebewertung'], array_column($filtered, 'column'));
    }

    public function test_from_contents_parses_raw_deliveries_like_the_import_does(): void
    {
        // Zwei Rohdateien wie sie auf der Platte liegen: BOM, CRLF, und die
        // zweite mit einer Spalte, die es in der ersten noch nicht gab. Genau
        // die drei Eigenheiten, an denen eine Auswertung per Hand scheitert.
        $a = "\xEF\xBB\xBFName;UplSelfie;Qualifikation;|\r\n"
            . "Ammerer;;;|\r\n"
            . "Mueller;bilder/2.jpg;;|\r\n";
        $b = "Name;UplSelfie;Qualifikation;StatusMASeit;|\r\n"
            . "Schmitz;;;01.08.2026;|\r\n";

        $report = array_column(
            $this->reportService()->fromContents([$a, $b], self::KNOWN, self::EXPECTED, 0),
            null,
            'column'
        );

        $this->assertSame(3, $report['Name']['filled'], 'BOM haette den ersten Spaltennamen verfaelscht');
        $this->assertSame(1, $report['UplSelfie']['filled']);
        $this->assertSame(3, $report['Qualifikation']['rows']);
        $this->assertSame(1, $report['StatusMASeit']['rows']);
        $this->assertSame(ZasInboundColumnReport::STATUS_MISSING, $report['Sternebewertung']['status']);
        // Das ZAS-Zeilenende ist eine echte Spalte und wird als solche gezaehlt.
        $this->assertSame(3, $report['|']['filled']);
    }

    public function test_handles_a_delivery_without_data_rows(): void
    {
        // Eine Lieferung mit Header aber ohne Zeilen darf nicht durch eine
        // Division durch Null fliegen — der Groessen-Waechter kann so eine
        // Datei stehen lassen.
        $built = $this->reportService()->build([['columns' => ['Name'], 'rows' => []]], self::KNOWN, ['Name'], 0);

        $this->assertSame(0, $built[0]['filled']);
        $this->assertSame(0, $built[0]['rows']);
        $this->assertSame(0.0, $built[0]['ratio']);
        $this->assertSame(ZasInboundColumnReport::STATUS_ALWAYS_EMPTY, $built[0]['status']);
    }
}
