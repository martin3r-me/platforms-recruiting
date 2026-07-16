<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Support\TerminWort;

class TerminWortTest extends TestCase
{
    public function test_femininum_alle_formen(): void
    {
        $w = TerminWort::fromParts('Vorstellungsrunde', 'f');
        $this->assertSame('Vorstellungsrunde', $w->nominativ());
        $this->assertSame('die Vorstellungsrunde', $w->akkusativMitArtikel());
        $this->assertSame('deine Vorstellungsrunde', $w->possessiv(true));
        $this->assertSame('Ihre Vorstellungsrunde', $w->possessiv(false));
    }

    public function test_neutrum_alle_formen(): void
    {
        $w = TerminWort::fromParts('Einzelgespräch', 'n');
        $this->assertSame('Einzelgespräch', $w->nominativ());
        $this->assertSame('das Einzelgespräch', $w->akkusativMitArtikel());
        $this->assertSame('dein Einzelgespräch', $w->possessiv(true));
        $this->assertSame('Ihr Einzelgespräch', $w->possessiv(false));
    }

    public function test_maskulinum_alle_formen(): void
    {
        $w = TerminWort::fromParts('Rundgang', 'm');
        $this->assertSame('Rundgang', $w->nominativ());
        $this->assertSame('den Rundgang', $w->akkusativMitArtikel());
        $this->assertSame('dein Rundgang', $w->possessiv(true));
        $this->assertSame('Ihr Rundgang', $w->possessiv(false));
    }

    public function test_fallback_ist_immer_das_komplette_paar_termin_maskulin(): void
    {
        // Nie Custom-Name mit Fallback-Artikel mischen: fehlt eine Hälfte, fällt ALLES zurück.
        foreach ([
            [null, null],
            [null, 'f'],
            ['', 'f'],
            ['   ', 'f'],
            ['Vorstellungsrunde', null],
            ['Vorstellungsrunde', ''],
            ['Vorstellungsrunde', 'x'],
        ] as [$name, $genus]) {
            $w = TerminWort::fromParts($name, $genus);
            $this->assertSame('Termin', $w->nominativ(), "name=" . var_export($name, true) . " genus=" . var_export($genus, true));
            $this->assertSame('den Termin', $w->akkusativMitArtikel());
            $this->assertSame('dein Termin', $w->possessiv(true));
            $this->assertSame('Ihr Termin', $w->possessiv(false));
        }
    }

    public function test_normalisierung_trim_und_case(): void
    {
        $w = TerminWort::fromParts('  Vorstellungsrunde  ', ' F ');
        $this->assertSame('Vorstellungsrunde', $w->nominativ());
        $this->assertSame('deine Vorstellungsrunde', $w->possessiv(true));
    }
}
