<?php

namespace Platform\Recruiting\Tests\Unit\Dispo;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\Dispo\ZasDispoWebexportImporter;

/**
 * notes-Spalte ist text (64 KB). Review 04.11.: bei einem Formatwechsel in
 * ZAS haengt jede Zeile einen Fehler an, der Abschluss scheiterte an
 * „Data too long“ — die Liste wird gekappt, die Gesamtzahl bleibt sichtbar.
 */
final class ZasDispoImporterNotizenTest extends TestCase
{
    public function test_kurze_fehlerliste_bleibt_unveraendert(): void
    {
        $n = json_decode(ZasDispoWebexportImporter::notizen(['blocks_found' => true, 'errors' => ['a', 'b']]), true);

        $this->assertSame(['a', 'b'], $n['errors']);
        $this->assertArrayNotHasKey('errors_gesamt', $n);
    }

    public function test_lange_fehlerliste_wird_gekappt_und_gezaehlt(): void
    {
        $fehler = array_map(fn ($i) => "Assignment {$i}: Datum unparsebar, uebersprungen", range(1, 4200));

        $n = json_decode(ZasDispoWebexportImporter::notizen(['blocks_found' => true, 'errors' => $fehler, 'matched' => 7]), true);

        $this->assertCount(ZasDispoWebexportImporter::MAX_FEHLER_IN_NOTES, $n['errors']);
        $this->assertSame('Assignment 1: Datum unparsebar, uebersprungen', $n['errors'][0]);
        $this->assertSame(4200, $n['errors_gesamt']);
        $this->assertSame(7, $n['matched'], 'alles andere bleibt');
        $this->assertLessThan(16 * 1024, strlen(ZasDispoWebexportImporter::notizen(['errors' => $fehler])), 'passt sicher in die Spalte');
    }
}
