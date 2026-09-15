<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Das Sprechblasen-Partial (dispo/_messages) erwartet ZWEI Variablen:
 * $messages und $portalUrl. Fehlt eine an einer Einbindung, faellt das
 * nicht beim Rendern der Seite auf, sondern erst beim Klick auf einen Chat.
 *
 * Seit der neuen Kommunikation (15.09.2026) binden zwei Ansichten es ein.
 * Dieser Test haelt fest, dass jede von ihnen beides mitgibt.
 */
class MessagesPartialIncludeContractTest extends TestCase
{
    private const PARTIAL = 'recruiting::livewire.dispo._messages';

    private const ANSICHTEN = [
        'resources/views/livewire/dispo/conversations/index.blade.php',
        'resources/views/livewire/conversations/inbox.blade.php',
    ];

    /** @dataProvider ansichten */
    public function test_einbindung_gibt_messages_und_portalurl_mit(string $relativerPfad): void
    {
        $pfad = dirname(__DIR__, 2) . '/' . $relativerPfad;
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
        foreach (self::ANSICHTEN as $ansicht) {
            $faelle[$ansicht] = [$ansicht];
        }

        return $faelle;
    }
}
