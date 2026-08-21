<?php

namespace Platform\Recruiting\Tests\Integration;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Models\RecDispoEvent;
use Platform\Recruiting\Support\Filialen;

/**
 * Zentrale Filial-Aufloesung: Nummer -> Code (Kundenwunsch: Kuerzel anzeigen),
 * plus die Fallback-Kette des Model-Accessors (Nummer schlaegt Roh-Text). Braucht
 * config() (deshalb Integration, nicht pur), aber keine DB.
 */
class DispoFilialenTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Container::getInstance()->instance('config', new ConfigRepository([
            'recruiting' => [
                'filialen' => [
                    100 => ['code' => 'DUS & ES', 'name' => 'Düsseldorf & Essen'],
                    200 => ['code' => 'MGL', 'name' => 'Mönchengladbach'],
                    400 => ['code' => 'CGN', 'name' => 'Köln'],
                ],
            ],
        ]));
    }

    public static function tearDownAfterClass(): void
    {
        Container::getInstance()->forgetInstance('config');
    }

    public function test_code_and_name_resolve_from_number(): void
    {
        $this->assertSame('CGN', Filialen::code(400));
        $this->assertSame('Köln', Filialen::name(400));
        $this->assertSame('DUS & ES', Filialen::code(100));
    }

    public function test_unknown_or_null_number_yields_null(): void
    {
        $this->assertNull(Filialen::code(999));
        $this->assertNull(Filialen::code(null));
        $this->assertNull(Filialen::name(null));
    }

    public function test_options_are_number_to_code(): void
    {
        $this->assertSame([100 => 'DUS & ES', 200 => 'MGL', 400 => 'CGN'], Filialen::options());
    }

    public function test_accessor_prefers_number_over_raw_text(): void
    {
        // Nummer bekannt -> kanonischer Code, egal was im Roh-Text steht.
        $event = new RecDispoEvent(['filial_nr' => 400, 'filiale' => 'irgendwas']);
        $this->assertSame('CGN', $event->filiale_label);
    }

    public function test_accessor_falls_back_to_raw_text_for_unknown_number(): void
    {
        $event = new RecDispoEvent(['filial_nr' => 777, 'filiale' => 'ROH']);
        $this->assertSame('ROH', $event->filiale_label);
    }

    public function test_accessor_null_when_nothing_present(): void
    {
        $event = new RecDispoEvent([]);
        $this->assertNull($event->filiale_label);
    }
}
