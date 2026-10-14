<?php

namespace Platform\Recruiting\Tests\Unit;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Livewire\Employees\Index;

/**
 * Mitarbeiter-Liste: Obergrenze fuer "Inaktiv" und "Alle".
 *
 * Seit ZAS die inaktiven Altbestaende liefert (~10.000), wuerde die Liste bei
 * diesen Filtern alles auf einmal laden. Gekappt wird bei 500 — NACH Suche und
 * Filtern, damit die Suche weiterhin jeden findet. Der Filter "Aktiv" bleibt
 * ungekappt.
 *
 * Die Komponente selbst bootet in dieser Suite nicht (auth()->user()); daher
 * die reine Logik als statische Helfer plus Quelltext-Vertrag fuer die
 * Verdrahtung.
 */
final class EmployeeListLimitTest extends TestCase
{
    public function test_aktiv_bleibt_ungekappt(): void
    {
        $this->assertNull(Index::listLimit('active'));
    }

    public function test_inaktiv_und_alle_sind_gekappt(): void
    {
        $this->assertSame(500, Index::LIST_LIMIT);
        $this->assertSame(500, Index::listLimit('inactive'));
        $this->assertSame(500, Index::listLimit('all'));
    }

    public function test_cap_meldet_nur_bei_mehr_als_der_grenze(): void
    {
        [$rows, $capped] = Index::capRows(new Collection(range(1, 501)), 500);
        $this->assertCount(500, $rows);
        $this->assertTrue($capped);

        [$rows, $capped] = Index::capRows(new Collection(range(1, 500)), 500);
        $this->assertCount(500, $rows);
        $this->assertFalse($capped, 'Genau 500 Treffer sind vollstaendig — kein Hinweis');

        [$rows, $capped] = Index::capRows(new Collection(range(1, 900)), null);
        $this->assertCount(900, $rows);
        $this->assertFalse($capped);
    }

    public function test_verdrahtung_query_und_hinweis(): void
    {
        $root = dirname(__DIR__, 2);
        $src  = file_get_contents($root . '/src/Livewire/Employees/Index.php');
        $view = file_get_contents($root . '/resources/views/livewire/employees/index.blade.php');

        // Eine Zeile mehr holen, um "mehr als 500" zu erkennen; die Grenze
        // greift nach den Such-/Filterbedingungen (gleiche Query).
        $this->assertMatchesRegularExpression('/\$limit\s*=\s*self::listLimit\(\$this->activeFilter\)/', $src);
        $this->assertStringContainsString('$query->limit($limit + 1)', $src);
        $this->assertStringContainsString('self::capRows(', $src);
        $limitPos  = strpos($src, '$query->limit($limit + 1)');
        $searchPos = strpos($src, "->orWhere('phone', 'like', \$needle)");
        $this->assertNotFalse($searchPos);
        $this->assertGreaterThan($searchPos, $limitPos, 'Grenze erst nach der Suche anwenden');

        $this->assertStringContainsString('$this->employeesCapped', $view);
        $this->assertStringContainsString(
            'Es werden die ersten 500 angezeigt — für weitere bitte die Suche nutzen.',
            $view
        );
    }
}
