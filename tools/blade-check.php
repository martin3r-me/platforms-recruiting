<?php

/**
 * Blade-Syntax-Check fuer Views dieses Moduls.
 *
 * Warum es das braucht: `php -l datei.blade.php` ist WERTLOS. Blade-Dateien
 * sind kein PHP; der Linter sieht Text und meldet immer "No syntax errors".
 * Dieses Skript kompiliert die Datei mit dem echten BladeCompiler und lintet
 * das ERZEUGTE PHP.
 *
 * Bewusst OHNE Laravel-App-Boot: meingedeck hat kein .env, und beim Booten
 * fragen Service-Provider (z. B. CrmServiceProvider) die Datenbank ab —
 * ein Check, der davon abhaengt, ist nicht reproduzierbar. Hier laeuft eine
 * UNGEBOOTETE Application (nur Basis-Bindings) plus eine Stub-View-Factory:
 * genug fuer die <x-ui-*>-Tag-Kompilierung, ohne .env und ohne DB.
 *
 * Aufruf (aus dem Modulverzeichnis):
 *   php tools/blade-check.php resources/views/livewire/public/contract-signing.blade.php
 *   php tools/blade-check.php            # alle Views unter resources/views
 *
 * Exit 0 = alles gruen. Exit 1 = mindestens ein Fund. Exit 2 = Setup-Fehler.
 *
 * GRENZE, die dieses Skript NICHT abdeckt: Ein `@php` ohne `@endphp` laesst
 * Blade als literalen Text stehen — das kompilierte PHP bleibt gueltig, der
 * Fehler zeigt sich erst im Browser. Dafuer gibt es hier die separate
 * Balance-Pruefung.
 */

$autoload = __DIR__ . '/../../../../meingedeck/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Autoloader nicht gefunden: {$autoload}\n");
    exit(2);
}
require $autoload;

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\View\Compilers\BladeCompiler;

/** Stub-Factory: der ComponentTagCompiler braucht nur exists(). */
final class BladeCheckViewFactory implements Illuminate\Contracts\View\Factory
{
    public function exists($view)
    {
        return true;
    }

    public function file($path, $data = [], $mergeData = [])
    {
        throw new RuntimeException('not needed for syntax check');
    }

    public function make($view, $data = [], $mergeData = [])
    {
        throw new RuntimeException('not needed for syntax check');
    }

    public function share($key, $value = null)
    {
        return $value;
    }

    public function composer($views, $callback)
    {
        return [];
    }

    public function creator($views, $callback)
    {
        return [];
    }

    public function addNamespace($namespace, $hints)
    {
        return $this;
    }

    public function replaceNamespace($namespace, $hints)
    {
        return $this;
    }
}

// Ungebootete Application: registriert nur die Basis-Bindings. KEIN
// bootstrap(), also keine Provider, kein .env, keine DB-Verbindung.
$hostApp = dirname($autoload, 2);
$app = new Application($hostApp);
$app->instance(Illuminate\Contracts\View\Factory::class, new BladeCheckViewFactory());
Container::setInstance($app);

$moduleRoot = dirname(__DIR__);
$targets = array_slice($argv, 1);

if ($targets === []) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($moduleRoot . '/resources/views', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
            $targets[] = $file->getPathname();
        }
    }
    sort($targets);
}

$cache = sys_get_temp_dir() . '/blade-check-' . getmypid();
@mkdir($cache, 0777, true);

$compiler = new BladeCompiler(new Filesystem(), $cache);
$failures = 0;
$checked = 0;

foreach ($targets as $target) {
    if (!is_file($target)) {
        fwrite(STDERR, "FEHLT   {$target}\n");
        $failures++;
        continue;
    }

    $checked++;
    $rel = str_replace($moduleRoot . '/', '', $target);
    $source = file_get_contents($target);
    $problems = [];

    // 1) @php/@endphp-Balance. Nur die Block-Form zaehlen — die Inline-Form
    //    `@php($x)` braucht kein @endphp. Blade-Kommentare vorher entfernen:
    //    in posting/show.blade.php steht die Warnung vor genau diesem Pitfall
    //    im Kommentar und nennt @endphp im Text — das darf nicht mitzaehlen.
    $countable = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    $blocks = preg_match_all('/@php(?![\s]*\()/', $countable);
    $ends   = preg_match_all('/@endphp\b/', $countable);
    if ($blocks !== $ends) {
        $problems[] = "@php-Bloecke: {$blocks}, @endphp: {$ends} — unbalanciert";
    }

    // 2) Kompilieren und das erzeugte PHP linten.
    try {
        $compiled = $compiler->compileString($source);
    } catch (Throwable $e) {
        $problems[] = 'Compile-Fehler: ' . $e->getMessage();
        $compiled = null;
    }

    if ($compiled !== null) {
        $tmp = $cache . '/' . md5($target) . '.php';
        file_put_contents($tmp, $compiled);

        $lint = [];
        $lintCode = 0;
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $lint, $lintCode);

        if ($lintCode !== 0) {
            $problems[] = 'Lint: ' . trim(preg_replace('/ in .*compiled.*$/m', '', implode(' | ', $lint)));
        }
    }

    if ($problems === []) {
        echo "OK      {$rel}\n";
    } else {
        $failures++;
        echo "FEHLER  {$rel}\n";
        foreach ($problems as $problem) {
            echo "        - {$problem}\n";
        }
    }
}

echo "\n{$checked} Datei(en) geprueft, {$failures} mit Funden.\n";
exit($failures > 0 ? 1 : 0);
