<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoBlockSplitter;

class ZasDispoBlockSplitterTest extends TestCase
{
    private ZasDispoBlockSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new ZasDispoBlockSplitter();
    }

    /** Reale Zeilen aus Michels Beispiel-Webexport (11.06.2026). */
    private function fixture(): string
    {
        return "\r\n{Dispo}\r\n"
            . "19.05.2026;BHG. BROICHCATERING GMBH <br/>Alte Schmiedehalle Halle 33<br/>10:30-21:15<br/>Servicekräfte<br/>;RG14;RG19077;1;RG13450;830363;10:30;21:15;2;0;Servicekräfte;;27,49;\r\n"
            . "20.05.2026;BHG. BROICHCATERING GMBH <br/>Alte Schmiedehalle Halle 33<br/>07:00-16:30<br/>Servicekräfte<br/>;RG14;RG19079;1;RG13450;830434;07:00;16:30;2;0;Servicekräfte;;27,49;\r\n"
            . "{Dispo2}\r\n"
            . "12.04.2026;Rhein-Energie-Stadion<br/>Aachener Straße 999<br/>Kassenhaus Nordwest/Medieneingang;RG19063;Supervisor 13:00-17:30;1;116340;1.FC Köln vs. Bremen;CGN;400;RG26;13:00;17:30;;1. FC Köln GmbH & Co. KGaA ;;2;Supervisor;_;51;\r\n"
            . "{Dispo4}\r\n"
            . "1;Küchenchef;RG1;\r\n";
    }

    public function test_splits_known_blocks_to_assoc_rows(): void
    {
        $result = $this->splitter->split($this->fixture());

        $this->assertCount(2, $result['known']['Dispo']);
        $row = $result['known']['Dispo'][0];
        $this->assertSame('RG14', $row['pnr']);
        $this->assertSame('RG19077', $row['einsatz_id']);
        $this->assertSame('830363', $row['ds_id']);
        $this->assertSame('10:30', $row['von']);
        $this->assertSame('2', $row['status_id']);
        $this->assertSame('27,49', $row['verrechnungssatz']);

        $row2 = $result['known']['Dispo2'][0];
        $this->assertSame('RG19063', $row2['einsatz_id']);
        $this->assertSame('1.FC Köln vs. Bremen', $row2['projektbezeichnung']);
        $this->assertSame('', $row2['ort']); // Feld 13 ist leer in der Fixture
        $this->assertSame('1. FC Köln GmbH & Co. KGaA', $row2['einsatzfirma']);
    }

    public function test_unknown_blocks_kept_raw(): void
    {
        $result = $this->splitter->split($this->fixture());
        $this->assertSame([['1', 'Küchenchef', 'RG1', '']], $result['unknown']['Dispo4']);
        $this->assertArrayNotHasKey('Dispo4', $result['known']);
    }

    public function test_lines_before_first_marker_are_ignored(): void
    {
        $result = $this->splitter->split("irgendwas;ohne;block\r\n{Dispo}\r\n19.05.2026;x;RG1;RG2;1;t;99;10:00;11:00;1;0;K;;1,0;\r\n");
        $this->assertCount(1, $result['known']['Dispo']);
    }

    public function test_junk_rows_in_known_block_skipped_and_counted(): void
    {
        $result = $this->splitter->split("{Dispo}\r\nnur-eine-zelle\r\n19.05.2026;x;RG1;RG2;1;t;99;10:00;11:00;1;0;K;;1,0;\r\n");
        $this->assertCount(1, $result['known']['Dispo']);
        $this->assertSame(1, $result['skipped']['Dispo']);
    }

    public function test_row_overhang_gets_col_keys_and_short_row_pads(): void
    {
        // 15 Werte auf 14 Dispo-Spalten -> Ueberhang col_14; 5 Werte -> Rest ''
        $long  = "1;2;3;4;5;6;7;8;9;10;11;12;13;14;15";
        $short = "1;2;3;4;5";
        $result = $this->splitter->split("{Dispo}\r\n{$long}\r\n{$short}\r\n");

        $this->assertSame('15', $result['known']['Dispo'][0]['col_14']);
        $this->assertSame('', $result['known']['Dispo'][1]['ds_id']);
    }

    public function test_empty_input(): void
    {
        $result = $this->splitter->split('');
        $this->assertSame([], $result['known']);
        $this->assertSame([], $result['unknown']);
    }

    public function test_columns_constant_shape(): void
    {
        $this->assertCount(14, ZasDispoBlockSplitter::COLUMNS['Dispo']);
        $this->assertCount(19, ZasDispoBlockSplitter::COLUMNS['Dispo2']);
        $this->assertSame('ds_id', ZasDispoBlockSplitter::COLUMNS['Dispo'][6]);
        $this->assertSame('projektbezeichnung', ZasDispoBlockSplitter::COLUMNS['Dispo2'][6]);
    }
}
