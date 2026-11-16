<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Der Zeit-Kern darf nichts aus dem restlichen Recruiting-Modul und nichts von der
 * Plattform kennen (Spec 2026-11-16 §2.1): keine fremden Platform-Namespaces, keine
 * Facades, keine globalen Laravel-Helfer. Erlaubt sind Carbon, Eloquent/Database
 * (Model, Connection, Ausnahmen) und Symfony-Uid — heute ohne jede Ausnahme.
 */
class ZeitKernArchitekturTest extends TestCase
{
    private const HELFER = ['config', 'auth', 'app', 'request', 'session'];

    public function test_kern_importiert_nichts_ausserhalb_von_zeit(): void
    {
        foreach ($this->dateien() as $datei) {
            $this->assertSame([], self::verstoesse(file_get_contents($datei)), basename($datei) . ' greift ausserhalb von Zeit zu');
        }
    }

    public function test_kern_hat_genug_dateien_fuer_eine_aussage(): void
    {
        $this->assertGreaterThanOrEqual(7, count($this->dateien()), 'zu wenige Dateien geprueft');
    }

    /** Der Waechter selbst: jede verbotene Form wird erkannt, die erlaubten nicht. */
    public function test_waechter_erkennt_die_verbotenen_formen(): void
    {
        $kopf = "<?php\nnamespace Platform\\Recruiting\\Zeit;\n";
        foreach ([
            'use Platform\\Recruiting\\Models\\RecEmployee;',
            '$x = \\Platform\\Recruiting\\Services\\Zeit\\ZeitSchalter::class;',
            'use Platform\\Core\\Models\\Team;',
            'use Platform\\Crm\\Models\\CrmContact;',
            'use Illuminate\\Support\\Facades\\DB;',
            '\\Illuminate\\Support\\Facades\\Log::warning("x");',
            '$t = config("recruiting.zeit");',
            '$u = auth()->id();',
            '$s = app(Stempeluhr::class);',
            '$s = \\app (Stempeluhr::class);',
            '$r = request("x");',
            '$v = session("x");',
            '/** @var \\Platform\\Recruiting\\Models\\RecEmployee $x */',
        ] as $zeile) {
            $this->assertNotSame([], self::verstoesse($kopf . $zeile . "\n"), $zeile);
        }

        foreach ([
            'use Platform\\Recruiting\\Zeit\\ZeitBezug;',
            'use Illuminate\\Database\\Connection;',
            'use Carbon\\Carbon;',
            '$x = $this->config("a"); $y = Foo::app("b"); $z = $o->session("c");',
            'function approbation(): void {}',
            '// app(Stempeluhr::class) loest der Aufrufer auf, nicht der Kern',
        ] as $zeile) {
            $this->assertSame([], self::verstoesse($kopf . $zeile . "\n"), $zeile);
        }
    }

    /** @return list<string> */
    private function dateien(): array
    {
        $dateien = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src/Zeit', \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->getExtension() === 'php') {
                $dateien[] = $f->getPathname();
            }
        }

        return $dateien;
    }

    /** @return list<string> */
    private static function verstoesse(string $code): array
    {
        $funde = [];

        // Namespaces: im ganzen Text, auch in Kommentaren (ein @var auf ein fremdes Modell ist schon Kopplung).
        preg_match_all('/Platform\\\\{1,4}+(?!Recruiting\\\\{1,4}+Zeit\b)[A-Za-z0-9_\\\\]+/', $code, $t);
        $funde = array_merge($funde, $t[0]);
        preg_match_all('/Illuminate\\\\{1,4}+Support\\\\{1,4}+Facades\b[A-Za-z0-9_\\\\]*/', $code, $t);
        $funde = array_merge($funde, $t[0]);

        // Globale Helfer: nur im ausgefuehrten Code (Kommentare duerfen sie nennen).
        $ohneKommentare = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $ohneKommentare .= is_array($token) ? $token[1] : $token;
        }
        preg_match_all('/(?<![A-Za-z0-9_>:$])(?:' . implode('|', self::HELFER) . ')\s*\(/', $ohneKommentare, $t);

        return array_merge($funde, $t[0]);
    }
}
