<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasInboundRowMapper;

/**
 * knownColumns() beantwortet die Frage "liest unser Import diese ZAS-Spalte
 * ueberhaupt?" — Grundlage der Spalte "gelesen?" im Analyse-Command.
 *
 * Der Drift-Schutz ist der eigentliche Zweck dieser Tests: die Liste wird aus
 * denselben Konstanten gespeist, die map() benutzt. Kommt spaeter ein Feld
 * dazu, ohne dass knownColumns() es kennt, faellt das hier auf — sonst wuerde
 * der Command eine gelesene Spalte als "lesen wir nicht" ausweisen und
 * jemanden zu einer unnoetigen Rueckfrage bei ZAS schicken.
 */
class ZasInboundKnownColumnsTest extends TestCase
{
    /** Die Mapping-Tabellen, deren Keys ZAS-Spaltennamen sind. */
    private const MAPPING_CONSTANTS = ['DIRECT', 'DATES', 'INTS', 'BOOLS', 'LOOKUPS', 'HR_DATES'];

    public function test_contains_every_column_from_the_mapping_tables(): void
    {
        $known     = ZasInboundRowMapper::knownColumns();
        $constants = (new \ReflectionClass(ZasInboundRowMapper::class))->getConstants();

        foreach (self::MAPPING_CONSTANTS as $name) {
            $this->assertArrayHasKey($name, $constants, "Konstante {$name} existiert nicht mehr — knownColumns() pruefen.");

            foreach (array_keys($constants[$name]) as $column) {
                $this->assertContains(
                    $column,
                    $known,
                    "Spalte '{$column}' aus {$name} fehlt in knownColumns()."
                );
            }
        }
    }

    public function test_contains_the_columns_handled_outside_the_mapping_tables(): void
    {
        // Diese sechs liest map() von Hand (Default, Sonderregeln, Schluessel)
        // und nicht ueber eine Tabelle — sie wuerden dem Reflection-Test oben
        // durchs Netz gehen.
        foreach (['Land', 'Status', 'StatusMASeit', 'Anstellungsart', 'UUID', 'ZasPersonalNr'] as $column) {
            $this->assertContains($column, ZasInboundRowMapper::knownColumns(), "Sonderfall '{$column}' fehlt.");
        }
    }

    public function test_does_not_claim_to_read_the_file_columns(): void
    {
        // Die Upl*-Spalten sind der Anlass des Commands: ZAS liefert sie,
        // wir uebernehmen daraus keine Dateien. Wuerden sie hier auftauchen,
        // versteckte der Bericht genau die Luecke, die er zeigen soll.
        foreach (ZasInboundRowMapper::knownColumns() as $column) {
            $this->assertStringStartsNotWith('Upl', $column, "Upl-Spalte '{$column}' wird nicht gelesen.");
        }
    }

    public function test_has_no_duplicates(): void
    {
        $known = ZasInboundRowMapper::knownColumns();

        $this->assertSame(array_values(array_unique($known)), $known);
    }
}
