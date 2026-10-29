<?php

namespace Platform\Recruiting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Portal-Startseite als Uebersicht (29.10.2026): kurze Kacheln mit Sprung
 * in den Bereich, keine doppelte Nachweisliste. Vertrag an der Quelle.
 */
class PortalStartUebersichtTest extends TestCase
{
    private static function start(): string
    {
        $b = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/livewire/public/portal-shell.blade.php');
        $von = strpos($b, '{{-- ---------------- START ---------------- --}}');
        $bis = strpos($b, '{{-- ---------------- EINSAETZE ---------------- --}}');

        return substr($b, (int) $von, (int) $bis - (int) $von);
    }

    public function test_keine_doppelte_nachweisliste_auf_start(): void
    {
        $this->assertStringNotContainsString('Liegt vor <span', self::start(), 'die Liste lebt unter Dokumente & Infos');
        $this->assertStringContainsString("@click=\"tab = 'docs'\"", self::start(), 'Sprung zu den Unterlagen');
    }

    public function test_kachel_naechster_einsatz_springt_in_den_reiter(): void
    {
        $s = self::start();
        $this->assertStringContainsString('@if ($naechsterEinsatz !== null)', $s);
        $this->assertStringContainsString("@click=\"tab = 'jobs'\"", $s);
        $this->assertStringContainsString("\$naechsterEinsatz['tag']", $s);
        $this->assertStringContainsString('sobald die Dispo dich einplant', $s, 'Leerzustand fuer Neue');
    }

    public function test_lohn_kontakt_und_startbildschirm_hinweis(): void
    {
        $s = self::start();
        $this->assertStringContainsString('$lohnAnleitungUrl', $s);
        $this->assertStringContainsString('zum 15. des Folgemonats', $s);
        $this->assertStringContainsString('$schreibUnsUrl', $s);
        $this->assertStringContainsString("localStorage", $s, 'Startbildschirm-Hinweis nur einmal');
        $this->assertStringContainsString('display-mode: standalone', $s, 'nicht zeigen, wenn schon installiert');
    }
}
