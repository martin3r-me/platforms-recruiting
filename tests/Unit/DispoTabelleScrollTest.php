<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 17.11.2026: Die Dispo-Tabellen scrollen seitlich, wenn die Seitenleiste
 * ausgeklappt ist. Auf der VA-Seite haengen im Tabellenkopf Spaltenfilter-
 * Menues — die Scroll-Huelle braucht dort eine Mindesthoehe, sonst schneidet
 * sie die Menues bei wenigen Zeilen ab.
 */
class DispoTabelleScrollTest extends TestCase
{
    private function blade(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/dispo/events/' . $name);
    }

    public function test_uebersicht_tabelle_scrollt_seitlich(): void
    {
        $this->assertMatchesRegularExpression('/<div class="hidden overflow-x-auto [^"]*lg:block">\s*<table/', $this->blade('index.blade.php'));
    }

    public function test_va_seite_scrollt_seitlich_mit_mindesthoehe_fuer_die_spaltenfilter(): void
    {
        $this->assertMatchesRegularExpression(
            '/<div class="hidden overflow-x-auto lg:block" style="min-height: \d+rem">\s*<table/',
            $this->blade('show.blade.php')
        );
        $this->assertStringContainsString('absolute', $this->blade('_column-filter.blade.php'), 'Annahme: das Filter-Menue haengt absolut im Tabellenkopf');
    }
}
