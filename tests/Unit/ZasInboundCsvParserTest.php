<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundCsvParser;

/**
 * Parsen der ZAS-Inbound-CSV.
 *
 * Die Logik lag als geschuetzte Methoden im Controller und war damit nur ueber
 * HTTP erreichbar — und ungetestet. Sie wird jetzt auch vom Reprocess-Command
 * gebraucht, deshalb als eigener Dienst.
 *
 * Zwei Eigenheiten sind VERTRAG, nicht Zufall, weil die Zeilen-Guards des
 * Importers darauf aufbauen:
 *  - mehr Werte als Spalten  => Ueberzaehlige landen als col_N-Keys
 *  - ZAS-Zeilenende `;|;`    => eine Spalte namens '|' mit dem Wert '|'
 */
class ZasInboundCsvParserTest extends TestCase
{
    private function parse(string $content): array
    {
        return (new ZasInboundCsvParser())->parse($content);
    }

    public function test_semikolon_wird_als_trennzeichen_erkannt(): void
    {
        $result = $this->parse("Name;Vorname\nSchlaffke;Marie\n");

        $this->assertSame(';', $result['delimiter']);
        $this->assertSame(['Name', 'Vorname'], $result['columns']);
    }

    public function test_kopfzeile_zaehlt_nicht_als_datenzeile(): void
    {
        $result = $this->parse("Name;Vorname\nA;B\nC;D\n");

        $this->assertSame(2, $result['row_count']);
        $this->assertCount(2, $result['rows']);
    }

    public function test_werte_werden_den_spalten_zugeordnet(): void
    {
        $result = $this->parse("Name;Vorname\nSchlaffke;Marie\n");

        $this->assertSame(['Name' => 'Schlaffke', 'Vorname' => 'Marie'], $result['rows'][0]);
        $this->assertSame(['Name' => 'Schlaffke', 'Vorname' => 'Marie'], $result['first_data_row']);
    }

    public function test_utf8_bom_wird_entfernt(): void
    {
        $result = $this->parse("\xEF\xBB\xBFName;Vorname\nA;B\n");

        $this->assertSame(['Name', 'Vorname'], $result['columns'], 'BOM darf nicht am ersten Spaltennamen kleben');
    }

    public function test_zu_viele_werte_landen_als_col_keys(): void
    {
        // Vertrag fuer den Spaltenversatz-Guard des Importers.
        $result = $this->parse("Name;Vorname\nSchlaffke;Marie;ueberzaehlig\n");

        $this->assertArrayHasKey('col_2', $result['rows'][0]);
        $this->assertSame('ueberzaehlig', $result['rows'][0]['col_2']);
    }

    public function test_zeilenende_marker_bleibt_als_eigene_spalte(): void
    {
        $result = $this->parse("Name;|\nSchlaffke;|\n");

        $this->assertSame('|', $result['rows'][0]['|']);
    }

    public function test_leerzeilen_werden_uebersprungen(): void
    {
        $result = $this->parse("Name;Vorname\n\nA;B\n\n");

        $this->assertSame(1, $result['row_count']);
    }

    public function test_leerer_inhalt_ergibt_leeres_ergebnis(): void
    {
        $result = $this->parse('');

        $this->assertNull($result['delimiter']);
        $this->assertSame([], $result['columns']);
        $this->assertSame(0, $result['row_count']);
        $this->assertSame([], $result['rows']);
        $this->assertNull($result['first_data_row']);
    }

    public function test_windows_zeilenumbrueche(): void
    {
        $result = $this->parse("Name;Vorname\r\nA;B\r\n");

        $this->assertSame(1, $result['row_count']);
        $this->assertSame('B', $result['rows'][0]['Vorname']);
    }
}
