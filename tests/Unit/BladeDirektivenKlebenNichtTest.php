<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Eine Blade-Direktive, die direkt an einem Wortzeichen klebt („Stempel@include(…)“, „da@if“), wird
 * NICHT kompiliert und steht als Klartext auf der Seite. tools/blade-check.php sieht das nicht, und
 * kein Test rendert die Seiten (Review 18.11.2026: Spaltenkopf „Stempel“).
 */
class BladeDirektivenKlebenNichtTest extends TestCase
{
    public function test_keine_direktive_klebt_an_einem_wortzeichen(): void
    {
        $wurzel = dirname(__DIR__, 2) . '/resources/views';
        $muster = '/[\p{L}\p{N}_]@(include|includeIf|includeWhen|if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|php|endphp|error|enderror|isset|endisset|unless|endunless|checked|selected|disabled|class|style|json|lang|can|endcan|auth|endauth|guest|endguest|switch|case|endswitch|for|endfor|while|endwhile)\b/u';
        $funde = [];
        $dateien = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($wurzel, \FilesystemIterator::SKIP_DOTS));
        foreach ($dateien as $datei) {
            if (!str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($datei->getPathname()) ?: [] as $nr => $zeile) {
                if (preg_match($muster, $zeile, $m)) {
                    $funde[] = substr($datei->getPathname(), strlen($wurzel) + 1) . ':' . ($nr + 1) . ' ' . $m[0];
                }
            }
        }
        $this->assertSame([], $funde, 'Direktive klebt an einem Wortzeichen und wuerde als Text ausgegeben');
    }
}
