<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Das Sprechblasen-Partial (dispo/_messages) erwartet ZWEI Variablen:
 * $messages und $portalUrl. Fehlt eine an einer Einbindung, faellt das
 * nicht beim Rendern der Seite auf, sondern erst beim Klick auf einen Chat.
 *
 * Seit der neuen Kommunikation (15.09.2026) binden mehrere Ansichten es ein.
 * Eine feste Liste bekannter Ansichten wuerde eine kuenftige DRITTE Einbindung
 * nicht sehen — der Test muesste dann genau das Risiko wiederholen, das er
 * bannen soll. Deshalb durchsucht er `resources/views` rekursiv nach jeder
 * Stelle, die das Partial einbindet, statt eine Liste zu pflegen.
 */
class MessagesPartialIncludeContractTest extends TestCase
{
    private const PARTIAL = 'recruiting::livewire.dispo._messages';

    public function test_findet_mindestens_eine_einbindung(): void
    {
        $this->assertNotEmpty(
            self::einbindendeAnsichten(),
            'Keine Ansicht bindet ' . self::PARTIAL . ' mehr ein — Pfad des '
            . 'Partials geaendert? Ohne diese Absicherung wuerde der Test '
            . 'unten stumm mit null Faellen "gruen" bleiben.',
        );
    }

    #[DataProvider('ansichten')]
    public function test_einbindung_gibt_messages_und_portalurl_mit(string $relativerPfad): void
    {
        $pfad = self::projektWurzel() . '/' . $relativerPfad;
        $this->assertFileExists($pfad);

        $inhalt = (string) file_get_contents($pfad);

        $treffer = [];
        preg_match_all(
            '/@include\(\s*[\'"]' . preg_quote(self::PARTIAL, '/') . '[\'"]\s*,(.*?)\)\s*$/ms',
            $inhalt,
            $treffer,
        );

        $this->assertNotEmpty(
            $treffer[1],
            "{$relativerPfad} bindet " . self::PARTIAL . " nicht (mehr) ein — Pfad geaendert?",
        );

        foreach ($treffer[1] as $argumente) {
            $this->assertStringContainsString("'messages'", $argumente,
                "{$relativerPfad} gibt \$messages nicht mit — der Verlauf bleibt leer.");
            $this->assertStringContainsString("'portalUrl'", $argumente,
                "{$relativerPfad} gibt \$portalUrl nicht mit — das bricht beim Klick auf einen Chat.");
        }
    }

    public static function ansichten(): array
    {
        $faelle = [];
        foreach (self::einbindendeAnsichten() as $relativerPfad) {
            $faelle[$relativerPfad] = [$relativerPfad];
        }

        return $faelle;
    }

    /** @return list<string> Relative Pfade (ab Modulwurzel) aller Ansichten, die das Partial einbinden. */
    private static function einbindendeAnsichten(): array
    {
        $wurzel = self::projektWurzel() . '/resources/views';
        $regex = '/@include\(\s*[\'"]' . preg_quote(self::PARTIAL, '/') . '[\'"]\s*,/';

        $treffer = [];
        foreach (self::alleBladeDateien($wurzel) as $absoluterPfad) {
            $inhalt = (string) file_get_contents($absoluterPfad);
            if (preg_match($regex, $inhalt) === 1) {
                $treffer[] = self::relativieren($absoluterPfad);
            }
        }

        sort($treffer);

        return $treffer;
    }

    /** @return list<string> Absolute Pfade aller *.blade.php unter $basisverzeichnis. */
    private static function alleBladeDateien(string $basisverzeichnis): array
    {
        $dateien = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basisverzeichnis, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $eintrag) {
            /** @var \SplFileInfo $eintrag */
            if ($eintrag->isFile() && str_ends_with($eintrag->getFilename(), '.blade.php')) {
                $dateien[] = $eintrag->getPathname();
            }
        }

        return $dateien;
    }

    private static function projektWurzel(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function relativieren(string $absoluterPfad): string
    {
        $praefix = self::projektWurzel() . '/';

        return str_starts_with($absoluterPfad, $praefix)
            ? substr($absoluterPfad, strlen($praefix))
            : $absoluterPfad;
    }
}
