<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "Vertraege bei Darkmodus nicht lesbar da weiss auf weissem Hintergrund"
 * (Clara/RHEINGEDECK, Protokoll 28.08.2026).
 *
 * Ursache liegt nicht bei den Vertraegen allein: Das Guest-Layout aus
 * platforms-core setzt am body eine helle Schriftfarbe fuer den Dunkelmodus.
 * Unsere oeffentlichen Seiten sind aber durchgehend hell gestaltet — weisse
 * Karten, `--ui-surface` ist ein festes Fast-Weiss ohne dunkle Variante.
 * Text ohne eigene Farbe erbt im Dunkelmodus also Weiss und steht unlesbar
 * auf hellem Grund. Beim Vertrag faellt es am staerksten auf, weil der
 * eingebettete Vertragstext ueberhaupt keine Klassen mitbringt.
 *
 * Gegenmittel: Die Seite legt an ihrer Wurzel eine Textfarbe fest. Ein
 * Dunkelmodus-Design gibt es hier nicht und soll es nicht geben; das Layout
 * gehoert platforms-core und wird nicht angefasst.
 *
 * NICHT in dieser Liste, mit Grund:
 *  - employee-assignments bringt eigene gescopte Stile mit (.rg-crew setzt
 *    `color: var(--ink)`) und ist dadurch immun.
 *  - interview-booking hat einen dunklen Verlauf im Hintergrund; ob dort Text
 *    auf Dunkel steht, ist ohne Sichtpruefung nicht zu entscheiden. Offen.
 *
 * Quelltext-Test, weil Blade in dieser Suite nicht gerendert wird
 * (Begruendung siehe PortalCertificateWiringTest). Die letzte Instanz bleibt
 * der Blick auf ein echtes Geraet im Dunkelmodus.
 */
class PublicPagesDarkModeTest extends TestCase
{
    /** Seiten ohne eigene Stile: sie muessen die Textfarbe selbst setzen. */
    private const HELL_GESTALTET = [
        'contract-signing',
        'applicant-portal',
        'employee-portal',
    ];

    public function test_light_only_pages_pin_a_text_colour_at_their_root(): void
    {
        foreach (self::HELL_GESTALTET as $page) {
            $root = $this->firstElement($page);

            $this->assertMatchesRegularExpression(
                '/\btext-gray-\d{3}\b/',
                $root,
                "$page: Wurzelelement ohne Textfarbe — Text erbt im Dunkelmodus das Weiss des Layouts: $root"
            );
        }
    }

    public function test_light_only_pages_stay_light_only(): void
    {
        // Ein halbes Dunkelmodus-Design waere schlimmer als keines: einzelne
        // dark:-Klassen auf hellen Karten sind genau die Ursache des Fehlers.
        // Blade-Kommentare zaehlen nicht mit, die landen nie im Browser.
        foreach (self::HELL_GESTALTET as $page) {
            $this->assertStringNotContainsString('dark:', $this->markup($page), "$page traegt dark:-Klassen");
        }
    }

    public function test_the_assignments_page_carries_its_own_colour(): void
    {
        // Haelt fest, WARUM die Seite oben fehlt — verliert sie ihre gescopte
        // Farbe, gehoert sie in die Liste.
        $this->assertMatchesRegularExpression(
            '/\.rg-crew\s*\{[^}]*color\s*:/s',
            $this->markup('employee-assignments'),
            'employee-assignments setzt keine eigene Textfarbe mehr — gehoert dann in HELL_GESTALTET'
        );
    }

    private function firstElement(string $page): string
    {
        foreach (explode("\n", $this->markup($page)) as $line) {
            if (str_starts_with(ltrim($line), '<')) {
                return trim($line);
            }
        }

        return '';
    }

    private function markup(string $page): string
    {
        $path = __DIR__ . "/../../resources/views/livewire/public/{$page}.blade.php";

        return preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents($path));
    }
}
