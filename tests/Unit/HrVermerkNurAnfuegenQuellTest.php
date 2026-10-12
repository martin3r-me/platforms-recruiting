<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Quell-Waechter: rec_hr_vermerke darf in src/ nirgends per Query Builder
 * geaendert oder geloescht werden (Eloquent-Events greifen dort nicht).
 */
final class HrVermerkNurAnfuegenQuellTest extends TestCase
{
    public function test_keine_update_oder_delete_kette_auf_der_vermerk_tabelle(): void
    {
        $treffer = [];
        $gefunden = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $datei) {
            if ($datei->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($datei->getPathname());
            if (!str_contains($code, 'rec_hr_vermerke')) {
                continue;
            }
            $gefunden++;
            if (preg_match('/rec_hr_vermerke[^;]*->\s*(update|delete|truncate|increment|decrement)\s*\(/s', $code)) {
                $treffer[] = $datei->getPathname();
            }
        }
        $this->assertGreaterThan(0, $gefunden, 'Wache laeuft ins Leere: Tabellenname nirgends gefunden');
        $this->assertSame([], $treffer, 'rec_hr_vermerke ist nur anfuegbar');
    }

    public function test_die_wache_erkennt_eine_verbotene_kette(): void
    {
        $muster = '/rec_hr_vermerke[^;]*->\s*(update|delete|truncate|increment|decrement)\s*\(/s';
        $this->assertSame(1, preg_match($muster, "DB::table('rec_hr_vermerke')\n    ->where('id', 1)\n    ->delete();"));
        $this->assertSame(0, preg_match($muster, "DB::table('rec_hr_vermerke')->insertGetId([]);\n\$x->delete();"));
    }
}
