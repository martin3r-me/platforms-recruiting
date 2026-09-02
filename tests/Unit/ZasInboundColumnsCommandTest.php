<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Console\Commands\ZasInboundColumns;
use Platform\Recruiting\Services\Zas\ZasColumnProfiler;
use Platform\Recruiting\Services\Zas\ZasEmployeeFieldResolver;
use Platform\Recruiting\Services\Zas\ZasInboundColumnReport;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;

/**
 * Die zwei entscheidungstragenden Teile des Analyse-Commands. Der
 * Storage-Zugriff bleibt in handle() und damit aus dem Test heraus (Muster:
 * ZasInboundReprocessCommandTest).
 */
class ZasInboundColumnsCommandTest extends TestCase
{
    private function command(): ZasInboundColumns
    {
        return new ZasInboundColumns(
            new ZasInboundColumnReport(new ZasColumnProfiler(), new ZasInboundCsvParser())
        );
    }

    public function test_expected_columns_cover_export_and_import_side(): void
    {
        $expected = $this->command()->expectedColumns();

        // Was wir an ZAS schicken, hat er in seiner Maske — bleibt es in der
        // Rueckrichtung leer, ist das eine echte Aussage und kein Zufall.
        foreach (ZasEmployeeFieldResolver::COLUMNS as $column) {
            $this->assertContains($column, $expected, "Export-Spalte '{$column}' fehlt in der Erwartung.");
        }

        // Und alles, was der Import lesen KANN, muss auftauchen — sonst wuerde
        // eine nie gelieferte Spalte stillschweigend aus dem Bericht fallen.
        foreach (ZasInboundRowMapper::knownColumns() as $column) {
            $this->assertContains($column, $expected, "Gelesene Spalte '{$column}' fehlt in der Erwartung.");
        }

        $this->assertSame(array_values(array_unique($expected)), $expected, 'Erwartungsliste enthaelt Dubletten.');
    }

    public function test_expected_columns_contain_the_file_columns_so_the_gap_is_visible(): void
    {
        // Der Anlass des Commands: die Upl-Spalten muessen im Bericht stehen,
        // auch wenn der Import sie nicht liest.
        $expected = $this->command()->expectedColumns();

        $this->assertContains('UplSelfie', $expected);
        $this->assertContains('UplAuweis', $expected);
    }

    public function test_table_rows_label_the_three_states(): void
    {
        $rows = $this->command()->tableRows([
            ['column' => 'Name',          'filled' => 3, 'rows' => 3, 'ratio' => 1.0,  'read' => true,  'status' => ZasInboundColumnReport::STATUS_FILLED,       'examples' => []],
            ['column' => 'Qualifikation', 'filled' => 0, 'rows' => 3, 'ratio' => 0.0,  'read' => false, 'status' => ZasInboundColumnReport::STATUS_ALWAYS_EMPTY, 'examples' => []],
            ['column' => 'Ersthelfer',    'filled' => 0, 'rows' => 0, 'ratio' => 0.0,  'read' => true,  'status' => ZasInboundColumnReport::STATUS_MISSING,      'examples' => []],
        ], false);

        $this->assertSame(['Name', '3', '3', '100%', 'ja', 'gefuellt'], $rows[0]);
        $this->assertSame(['Qualifikation', '0', '3', '0%', '—', 'immer leer'], $rows[1]);
        // Ohne Zeilen gibt es keine Quote — ein "0%" waere hier eine Behauptung
        // ueber Daten, die nie geliefert wurden.
        $this->assertSame(['Ersthelfer', '0', '0', '—', 'ja', 'fehlt'], $rows[2]);
    }

    public function test_table_rows_append_examples_only_when_asked(): void
    {
        $entry = [['column' => 'UplSelfie', 'filled' => 1, 'rows' => 2, 'ratio' => 0.5, 'read' => false, 'status' => ZasInboundColumnReport::STATUS_FILLED, 'examples' => ['bilder/2.jpg', 'bilder/3.jpg']]];

        $this->assertCount(6, $this->command()->tableRows($entry, false)[0]);

        $withSamples = $this->command()->tableRows($entry, true)[0];
        $this->assertCount(7, $withSamples);
        $this->assertSame('bilder/2.jpg | bilder/3.jpg', $withSamples[6]);
    }
}
