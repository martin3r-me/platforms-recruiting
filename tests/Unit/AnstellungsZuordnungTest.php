<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\AnstellungsZuordnung;

class AnstellungsZuordnungTest extends TestCase
{
    public function test_genau_eine_passende_ist_zugeordnet(): void
    {
        $z = AnstellungsZuordnung::aus([17], hatAndereAnstellungen: false);
        $this->assertSame(AnstellungsZuordnung::ZUGEORDNET, $z->befund);
        $this->assertSame(17, $z->anstellungId());
        $this->assertSame(17, $z->ersterKandidatId());
    }

    public function test_mehrere_passende_sind_mehrdeutig_und_aufsteigend(): void
    {
        $z = AnstellungsZuordnung::aus([23, 4], hatAndereAnstellungen: false);
        $this->assertSame(AnstellungsZuordnung::MEHRDEUTIG, $z->befund);
        $this->assertNull($z->anstellungId(), 'mehrdeutig ordnet nichts zu');
        $this->assertSame(4, $z->ersterKandidatId());
        $this->assertSame([4, 23], $z->kandidatenIds);
    }

    public function test_keine_passende_aber_andere_ist_firma_fehlt(): void
    {
        $z = AnstellungsZuordnung::aus([], hatAndereAnstellungen: true);
        $this->assertSame(AnstellungsZuordnung::FIRMA_FEHLT, $z->befund);
        $this->assertNull($z->anstellungId());
        $this->assertNull($z->ersterKandidatId());
    }

    public function test_gar_keine_anstellung(): void
    {
        $this->assertSame(AnstellungsZuordnung::OHNE_ANSTELLUNG, AnstellungsZuordnung::aus([], false)->befund);
    }
}
