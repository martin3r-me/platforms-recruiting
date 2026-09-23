<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Platform\Recruiting\Services\Zas\ZasFieldResolver;
use ReflectionClass;

/**
 * Auch der BEWERBER-Export schickt Steuer-ID und SV-Nummer — direkt aus den
 * Formularfeldern, am Mitarbeiter und damit am Modell-Mutator vorbei. Ohne
 * eigene Behandlung gingen die Leerzeichen hier weiterhin nach ZAS.
 *
 * Quelltext-Test: der Resolver ist ohne Laravel-Bootstrap nicht baubar
 * (Lookup-Resolver, Extra-Field-Definitionen), und gemessen wird bewusst nur,
 * DASS beide Felder durch die Normalisierung laufen.
 */
class ZasApplicantNumberExportTest extends TestCase
{
    public function testBeideNummernLaufenDurchDieNormalisierung(): void
    {
        $src = file_get_contents((new ReflectionClass(ZasFieldResolver::class))->getFileName());

        foreach (["'SVNummer'", "'SteuerID'"] as $spalte) {
            $zeile = $this->zeileMit($src, $spalte);
            $this->assertStringContainsString(
                'TaxAndSvNumber::normalize(',
                $zeile,
                "$spalte geht ohne Normalisierung nach ZAS: $zeile"
            );
        }
    }

    private function zeileMit(string $src, string $needle): string
    {
        foreach (explode("\n", $src) as $zeile) {
            if (str_contains($zeile, $needle) && str_contains($zeile, '=>')) {
                return trim($zeile);
            }
        }
        return '';
    }
}
