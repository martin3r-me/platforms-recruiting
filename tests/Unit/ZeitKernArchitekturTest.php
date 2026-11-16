<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Der Zeit-Kern darf nichts aus dem restlichen Recruiting-Modul kennen (Spec 2026-11-16 §2.1). */
class ZeitKernArchitekturTest extends TestCase
{
    public function test_kern_importiert_nichts_ausserhalb_von_zeit(): void
    {
        $dateien = glob(dirname(__DIR__, 2) . '/src/Zeit/*.php');
        $this->assertGreaterThanOrEqual(7, count($dateien), 'zu wenige Dateien geprueft');

        foreach ($dateien as $datei) {
            $code = file_get_contents($datei);
            preg_match_all('/Platform\\\\Recruiting\\\\(?!Zeit\\b)[A-Za-z0-9_\\\\]*/', $code, $treffer);
            $this->assertSame([], $treffer[0], basename($datei) . ' greift ausserhalb von Zeit zu');
        }
    }
}
